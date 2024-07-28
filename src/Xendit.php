<?php
use Monolog\Logger;
use Monolog\Handler\StreamHandler;
use Monolog\Handler\RotatingFileHandler;

if (file_exists(__DIR__ . '/../vendor/autoload.php')) {
    require_once __DIR__ . '/../vendor/autoload.php';
} elseif (file_exists(__DIR__ . '/vendor/autoload.php')) {
    require_once __DIR__ . '/vendor/autoload.php';
} else {
    die('Autoloader not found.');
}

/**
 * Xendit FOSSBilling Integration.
 *
 * @property mixed $apiId
 * @author github.com/FZFR
 */
class Payment_Adapter_Xendit implements \FOSSBilling\InjectionAwareInterface
{
    protected ?Pimple\Container $di = null;
    private array $config = [];
    private Logger $logger;

    public function __construct($config)
    {
        $this->config = $config;
        
        $apiKey = $this->getApiKey();
        $webhookToken = $this->getWebhookToken();
        
        if (empty($apiKey)) {
            throw new Payment_Exception('The ":pay_gateway" payment gateway is not fully configured. Please configure the :missing', [':pay_gateway' => 'Xendit', ':missing' => 'API Key']);
        }
        if (empty($webhookToken)) {
            throw new Payment_Exception('The ":pay_gateway" payment gateway is not fully configured. Please configure the :missing', [':pay_gateway' => 'Xendit', ':missing' => 'Webhook Verification Token']);
        }

        $this->initLogger();
    }

    private function initLogger(): void
    {
        $this->logger = new Logger('Xendit');
        $logDir = __DIR__ . '/logs';
        if (!is_dir($logDir)) {
            mkdir($logDir, 0755, true);
        }
        $this->logger->pushHandler(new RotatingFileHandler($logDir . '/xendit.log', 0, Logger::DEBUG));
    }

    public function setDi(Pimple\Container $di): void
    {
        $this->di = $di;
    }

    public function getDi(): ?Pimple\Container
    {
        return $this->di;
    }

    public static function getConfig(): array
    {
        return [
            'supports_one_time_payments' => true,
            'description' => 'Configure Xendit API key and Webhook Verification Token to start accepting payments via Xendit.',
            'logo' => [
                'logo' => 'Xendit.png',
                'height' => '60px',
                'width' => '60px',
            ],
            'form' => [
                'api_key' => [
                    'text',
                    [
                        'label' => 'Xendit API Key',
                        'required' => true,
                    ],
                ],
                'sandbox_api_key' => [
                    'text',
                    [
                        'label' => 'Xendit Sandbox API Key',
                        'required' => false,
                    ],
                ],
                'webhook_token' => [
                    'text',
                    [
                        'label' => 'Webhook Verification Token',
                        'required' => true,
                    ],
                ],
                'sandbox_webhook_token' => [
                    'text',
                    [
                        'label' => 'Sandbox Webhook Verification Token',
                        'required' => false,
                    ],
                ],
                'use_sandbox' => [
                    'radio',
                    [
                        'label' => 'Use Sandbox',
                        'multiOptions' => [
                            '1' => 'Yes',
                            '0' => 'No',
                        ],
                        'required' => true,
                    ],
                ],
                'enable_logging' => [
                    'radio',
                    [
                        'label' => 'Enable Logging',
                        'multiOptions' => [
                            '1' => 'Yes',
                            '0' => 'No',
                        ],
                        'required' => true,
                    ],
                ],
            ],
        ];
    }

    private function getConfigValue($key)
    {
        $prefix = $this->config['use_sandbox'] ? 'sandbox_' : '';
        return $this->config[$prefix . $key] ?? null;
    }

    public function getHtml($api_admin, $invoice_id, $subscription)
    {
        try {
            $invoice = $this->di['db']->load('Invoice', $invoice_id);
            $xenditInvoice = $this->createXenditInvoice($invoice);
            
            if ($this->config['enable_logging']) {
                $this->logger->info('Xendit invoice created: ' . json_encode($xenditInvoice));
            }
            
            return $this->generatePaymentForm($xenditInvoice['invoice_url'], $invoice->id);
        } catch (Exception $e) {
            if ($this->config['enable_logging']) {
                $this->logger->error('Error in getHtml: ' . $e->getMessage());
            }
            throw new Payment_Exception('Error processing Xendit payment: ' . $e->getMessage());
        }
    }

    private function generatePaymentForm($invoiceUrl, $invoiceId): string
    {
        $html = '<form id="xendit-payment-form" method="get" action="' . $invoiceUrl . '">';
        $html .= '<input type="hidden" name="external_id" value="' . $invoiceId . '">';
        $html .= '<input type="submit" value="Pay with Xendit" style="display:none;">';
        $html .= '</form>';
        $html .= '<script type="text/javascript">document.getElementById("xendit-payment-form").submit();</script>';
        return $html;
    }

    public function processTransaction($api_admin, $id, $data, $gateway_id)
    {
        if ($this->config['enable_logging']) {
            $this->logger->info('Received Xendit callback: ' . json_encode($data));
            $this->logger->info('GET data: ' . json_encode($_GET));
            $this->logger->info('POST data: ' . json_encode($_POST));
            $this->logger->info('Raw input: ' . file_get_contents('php://input'));
        }

        if (isset($data['get']['bb_invoice_id'])) {
            return $this->handleSuccessRedirect($data['get']['bb_invoice_id'], $gateway_id);
        }

        return $this->handleWebhook($id, $data);
    }

    private function handleSuccessRedirect($invoice_id, $gateway_id)
    {
        $invoiceModel = $this->di['db']->load('Invoice', $invoice_id);
        
        if ($invoiceModel->status !== 'paid') {
            $tx = $this->createOrUpdateTransaction($invoice_id, $gateway_id, $invoiceModel);
            $this->processPayment($invoiceModel, $tx);
            if ($this->config['enable_logging']) {
                $this->logger->info('Xendit payment processed for invoice #' . $invoice_id);
            }
        } else {
            if ($this->config['enable_logging']) {
                $this->logger->info('Invoice #' . $invoice_id . ' is already paid. Skipping processing.');
            }
        }

        return true;
    }

    private function handleWebhook($id, $data)
    {
        $headers = getallheaders();
        $callbackToken = $headers['X-CALLBACK-TOKEN'] ?? '';
        if (!$this->verifyWebhookToken($callbackToken)) {
            $this->logger->error('Invalid Xendit webhook token');
            http_response_code(403);
            return false;
        }

        $rawInput = file_get_contents('php://input');
        $ipn = json_decode($rawInput, true);
        
        if ($this->config['enable_logging']) {
            $this->logger->info('Xendit webhook raw input: ' . $rawInput);
            $this->logger->info('Xendit webhook decoded: ' . json_encode($ipn));
        }

        if (!isset($ipn['external_id'])) {
            $this->logger->error('Invalid Xendit callback: missing external_id');
            http_response_code(400);
            return false;
        }

        $invoice_id = $ipn['external_id'];
        $tx = $this->di['db']->load('Transaction', $id);

        if (!$tx->invoice_id) {
            $tx->invoice_id = $invoice_id;
        }

        $invoiceModel = $this->di['db']->load('Invoice', $invoice_id);

        if ($this->config['enable_logging']) {
            $this->logger->info('Xendit payment status: ' . $ipn['status']);
        }

        if ($ipn['status'] == 'PAID') {
            $result = $this->processSuccessfulPayment($tx, $invoiceModel, $ipn);
        } else {
            $result = $this->handleFailedPayment($tx, $invoice_id, $ipn['status']);
        }

        http_response_code(200);
        return $result;
    }

    private function processSuccessfulPayment($tx, $invoiceModel, $ipn)
    {
        $this->updateTransaction($tx, $ipn);
        $this->processPayment($invoiceModel, $tx);

        if ($this->config['enable_logging']) {
            $this->logger->info('Xendit payment processed successfully for invoice #' . $invoiceModel->id);
        }
        return true;
    }

    private function handleFailedPayment($tx, $invoice_id, $status)
    {
        $tx->error = 'Xendit payment status: ' . $status;
        $tx->status = 'received';
        $tx->updated_at = date('Y-m-d H:i:s');
        $this->di['db']->store($tx);

        if ($this->config['enable_logging']) {
            $this->logger->info('Xendit payment not completed for invoice #' . $invoice_id . '. Status: ' . $status);
        }
        return false;
    }

    private function createOrUpdateTransaction($invoice_id, $gateway_id, $invoiceModel)
    {
        $tx = $this->di['db']->find_one('Transaction', 'invoice_id = ?', [$invoice_id]);
        if (!$tx) {
            $tx = $this->di['db']->dispense('Transaction');
            $tx->invoice_id = $invoice_id;
        }

        $tx->txn_status = 'complete';
        $tx->status = 'complete';
        $tx->amount = $invoiceModel->total;
        $tx->currency = $invoiceModel->currency;
        $tx->type = 'payment';
        $tx->gateway_id = $gateway_id;
        $tx->updated_at = date('Y-m-d H:i:s');
        $this->di['db']->store($tx);

        return $tx;
    }

    private function processPayment($invoiceModel, $tx)
    {
        $invoiceService = $this->di['mod_service']('Invoice');
        $paymentService = $this->di['mod_service']('Invoice', 'Payment');

        $paymentService->recordPayment($invoiceModel->id, $tx->amount, 'Xendit payment', $tx);

        $clientService = $this->di['mod_service']('Client');
        $client = $this->di['db']->getExistingModelById('Client', $invoiceModel->client_id);
        $clientService->addFunds($client, $tx->amount, 'Xendit payment', [
            'type' => 'Xendit',
            'rel_id' => $tx->id,
        ]);

        $invoiceService->payInvoiceWithCredits($invoiceModel);
        $invoiceService->doBatchPayWithCredits(['client_id' => $invoiceModel->client_id]);

        if ($this->config['enable_logging']) {
            $this->logger->info('Invoice #' . $invoiceModel->id . ' marked as paid and product activation triggered');
        }
    }

    private function updateTransaction($tx, $ipn)
    {
        $tx->txn_status = 'complete';
        $tx->txn_id = $ipn['id'];
        $tx->amount = $ipn['paid_amount'] ?? $ipn['amount'];
        $tx->currency = $ipn['currency'];
        $tx->status = 'complete';
        $tx->updated_at = date('Y-m-d H:i:s');
        $this->di['db']->store($tx);
    }

    private function createXenditInvoice($invoice)
    {
        $invoiceService = $this->di['mod_service']('Invoice');

        if (!$invoice instanceof \Model_Invoice) {
            $invoice = $this->di['db']->load('Invoice', $invoice->id);
        }

        $thankyou_url = $this->di['url']->link('/invoice/thank-you/' . $invoice->hash, [
            'bb_invoice_id' => $invoice->id, 
            'gateway_id' => 10,
            'restore_session' => session_id()
        ]);
        $invoice_url = $this->di['tools']->url('/invoice/' . $invoice->hash, ['restore_session' => session_id()]);

        $items = $this->di['db']->getAll("SELECT title FROM invoice_item WHERE invoice_id = :invoice_id", [':invoice_id' => $invoice->id]);
        
        $description = $this->createDetailedDescription($invoice, $items);

        $data = [
            'external_id' => (string) $invoice->id,
            'amount' => $invoiceService->getTotalWithTax($invoice),
            'payer_email' => $invoice->buyer_email,
            'description' => $description,
            'success_redirect_url' => $thankyou_url,
            'failure_redirect_url' => $invoice_url,
            'currency' => $invoice->currency,
        ];

        if ($this->config['enable_logging']) {
            $this->logger->info('Creating Xendit invoice with data: ' . json_encode($data));
        }

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, 'https://api.xendit.co/v2/invoices');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Authorization: Basic ' . base64_encode($this->getApiKey() . ':')
        ]);

        $response = curl_exec($ch);
        if (curl_errno($ch)) {
            if ($this->config['enable_logging']) {
                $this->logger->error('Xendit API Error: ' . curl_error($ch));
            }
            throw new Payment_Exception('Error creating Xendit invoice: ' . curl_error($ch));
        }
        curl_close($ch);

        $result = json_decode($response, true);
        if (!isset($result['invoice_url'])) {
            if ($this->config['enable_logging']) {
                $this->logger->error('Xendit API Error: ' . $response);
            }
            throw new Payment_Exception('Invalid response from Xendit: ' . $response);
        }

        if ($this->config['enable_logging']) {
            $this->logger->info('Xendit invoice created: ' . json_encode($result));
        }

        return $result;
    }
    private function createDetailedDescription($invoice, $items)
    {
        $description = "Invoice " . $invoice->serie . $invoice->nr;
        
        foreach ($items as $item) {
            $description .= " | " . $item['title'];
        }
        
        if (strlen($description) > 255) {
            $description = substr($description, 0, 252) . '...';
        }

        return $description;
    }

    private function verifyWebhookToken($token)
    {
        return hash_equals($this->getWebhookToken(), $token);
    }

    private function getApiKey()
    {
        return $this->config['use_sandbox'] ? $this->config['sandbox_api_key'] : $this->config['api_key'];
    }

    private function getWebhookToken()
    {
        return $this->config['use_sandbox'] ? $this->config['sandbox_webhook_token'] : $this->config['webhook_token'];
    }
}