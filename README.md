<div align="center">
  <img src="https://cdn.fazza.fr/REDACTED/img/xendit-foss-banner.jpg" alt="Xendit for FOSSBilling">
  <h1>Xendit Integration for FOSSBilling</h1>
  <img src="https://img.shields.io/github/v/release/FZFR/Xendit-FOSSBilling?include_prereleases&sort=semver&display_name=release&style=flat">
  <img src="https://img.shields.io/github/downloads/FZFR/Xendit-FOSSBilling/total?style=flat">
  <img src="https://img.shields.io/github/repo-size/FZFR/Xendit-FOSSBilling">
  <img alt="GitHub" src="https://img.shields.io/github/license/FZFR/Xendit-FOSSBilling?style=flat">  
</div>

## Overview
Provide your [FOSSBilling](https://fossbilling.org) customers with a variety of payment options, including Credit/Debit cards, Bank Transfer, E-Wallets, and more through [Xendit](https://www.xendit.co).

> **Note**
> Warning This extension, like FOSSBilling itself is under active development but is currently very much beta software. This means that there may be stability or security issues and it is not yet recommended for use in active production environments!

## Table of Contents
- [Overview](#overview)
- [Table of Contents](#table-of-contents)
- [Installation](#installation)
  - [1). Extension directory](#1-extension-directory)
  - [2). Manual installation](#2-manual-installation)
- [Configuration](#configuration)
  - [Webhook Configuration](#webhook-configuration)
- [Usage](#usage)
- [Troubleshooting](#troubleshooting)
- [Features](#features)
- [Contributing](#contributing)
- [License](#license)
- [Support](#support)

## Installation

### 1). Extension directory
The easiest way to install this extension is by using the [FOSSBilling extension directory](https://extensions.fossbilling.org/extension/Xendit).
### 2). Manual installation
1. Download the latest release from the [GitHub repository](https://github.com/FZFR/Xendit-FOSSBilling/releases)
2. Create a new folder named **Xendit** in the **/library/Payment/Adapter** directory of your FOSSBilling installation
3. Extract the archive you've downloaded in the first step into the new directory
4. Go to the "**Payment gateways**" page in your admin panel (under the "System" menu in the navigation bar) and find Xendit in the "**New payment gateway**" tab
5. Click the *cog icon* next to Xendit to install and configure Xendit

## Configuration
1. Access Xendit Settings: In your FOSSBilling admin panel, find "**Xendit**" under "**Payment gateways.**"
2. Enter API Credentials: Input your Xendit `API Key` and `Webhook Verification Token`. You can obtain these from your Xendit dashboard.
3. Configure Preferences: Customize settings like sandbox mode and logging as needed.
4. Save Changes: Remember to update your configuration.
5. Test Transactions: It's recommended to test your gateway integration through a payment process in sandbox mode before going live.
6. Go Live: Switch to live mode to start accepting real payments once testing is complete.


### Webhook Configuration

To set up webhooks:

1. Log in to your Xendit dashboard.
2. Navigate to Settings > Webhooks.
3. Add a new webhook with the following URL:
   `https://your-fossbilling-domain.com/ipn.php?gateway_id=payment_gateway_id`
   (Replace `your-fossbilling-domain.com` with your actual domain and `payment_gateway_id` with the ID assigned by FOSSBilling)
4. Ensure the Webhook Verification Token in your Xendit settings matches the one in your FOSSBilling configuration.



## Usage
Once installed and configured, Xendit will appear as a payment option during the checkout process. The module handles various payment statuses including successful payments, pending transactions, and failed attempts.

## Troubleshooting

- Check the logs at `library/Payment/Adapter/Xendit/logs/xendit.log` for detailed information on transactions and errors.
- Ensure your server's IP is whitelisted in Xendit's settings if you're experiencing connection issues.
- Verify that the API keys and Webhook Verification Tokens are correctly entered in the FOSSBilling configuration.
- If you encounter timezone-related issues, check your php.ini configuration or server settings.

## Features

- [x] Using Xendit Payment Link (https://docs.xendit.co/payment-link)
- [x] Automatic invoice status update to 'paid' upon successful payment
- [x] Activate service automatically after payment confirmation
- [x] Comprehensive handling of various payment statuses (PAID, EXPIRED, PENDING, FAILED)
- [x] Detailed transaction logging for easy tracking and debugging


## Contributing
We welcome contributions to enhance and improve this integration module. If you'd like to contribute, please follow these steps:

1. Fork the repository.
2. Create a new branch for your feature or bugfix: `git checkout -b feature-name`.
3. Make your changes and commit them with a clear and concise commit message.
4. Push your branch to your fork: `git push origin feature-name` and create a [pull request](https://github.com/FZFR/Xendit-FOSSBilling/pulls).

## License
This FOSSBilling Xendit Payment Gateway Integration module is open-source software licensed under the [Apache License 2.0](LICENSE).

> *Note*: This module is not officially affiliated with [FOSSBilling](https://fossbilling.org) or [Xendit](https://www.xendit.co). Please refer to their respective documentation for detailed information on FOSSBilling and Xendit.


## Support

For issues related to this adapter, please open an issue.

For Xendit-specific issues, please contact Xendit support.
