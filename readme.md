# Avacy WordPress Plugin

Contributors: Jumpgroup SRL
Tags: consent, cookie, cookie banner, tracking, privacy, gdpr, cookie consent, cookie notice, privacy policy
Requires at least: 4.9
Tested up to: 6.4
Stable tag: 1.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html


## Overview

The WordPress Consent Solution Plugin is a powerful tool that empowers website owners to easily configure a Consent Management Platform (CMP) and preemptively block all trackers on their website. This plugin ensures compliance with privacy regulations by providing users with the ability to manage their consent preferences for tracking technologies.

## Features

1. Consent Management Platform (CMP)
User-Friendly Interface: The plugin offers an intuitive dashboard for configuring and customizing the Consent Management Platform to suit your website's needs.
Customizable Cookie Banners: Easily create and customize consent banners to inform users about the use of trackers on your website.
Consent Logging: Keep track of user consent preferences and ensure compliance with privacy regulations.
2. Tracker Preemptive Blocking
Comprehensive Tracker Database: The plugin includes a regularly updated database of known trackers. It enables you to preemptively block these trackers, enhancing user privacy.
Granular Control: Customize which trackers to block and manage exceptions based on user consent.

## Installation

Download: Download the plugin ZIP file from the WordPress Plugin Repository.
Upload: Upload the ZIP file to your WordPress site through the admin interface or via FTP.
Activate: Activate the plugin through the 'Plugins' menu in WordPress.

## Usage

### Cookie Banners:

Cookie banners will automatically appear on your site once configured.
Users can manage their consent preferences through these banners.

### Tracker Control:
Trackers are preemptively blocked based on a blacklist JSON file hosted on an AWS content delivery network.
Every time a page in the website is loaded, Avacy checks if scripts injected in the current page have `src` attribute or the `innerHTML` property containing a string matching the patterns in the blacklist and, if so, overrides some attributes and blocks the script.
Every script blocked this way will be launched only if user grants consent to the corresponding vendor and purposes by opting-in from the first layer or by selecting the vendor and its purposes from the second layer (the preference center).

### Support and Feedback
For support or to report issues, please visit our support forum.

### Avacy Consent Solution API
The plugin provides an interface that allows connecting to your consent archive on Avacy. To do this, it's necessary to generate a token from the Avacy platform and insert it into the Token field in the API token tab. This way, all the forms present on the page will be visible, and it will be possible to select various options, including:

* the ability to store consents given for that specific form in the consent archive;
* the fields to store for that form;
* a field to identify the user who has given the consent to be stored in the archive.

Everytime a user submits his data from a contact form, Avacy will perform a POST request towards Avacy Consent API, using the previously generated token as authenticator.

#### Under What Circumstances is Avacy Used?
Avacy is utilized whenever users provide consent through forms or interactions on your WordPress site. The plugin sends this consent data to Avacy's servers for storage and processing.

#### Avacy Service Link
For more information about Avacy and its features, visit Avacy's [website](https://avacysolution.com/).

#### Avacy Terms of Use and Privacy Policies
Avacy uses 3rd party services as the Avacy REST API and AWS distributions to provide assets as the blacklist JSON file.
Before integrating Avacy with this plugin, please review the Avacy [Terms of use](https://avacy.eu/terms-and-conditions) and [Privacy Policy](https://api.avacy.eu/jumpgroup/privacypolicy/14/it) to understand how your data is handled and what responsibilities you have as a user of their service.

We welcome your feedback and suggestions to improve the functionality of the plugin.

## License

This WordPress Consent Solution Plugin is licensed under the [GNU General Public License v2.0 or later](https://www.gnu.org/licenses/gpl-2.0.html).
