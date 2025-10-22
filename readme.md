# Avacy CMP

Contributors: jumptech
Author: Jump Group
Tags: cookie banner, gdpr, cookie consent, privacy policy, consent
Requires at least: 4.9
Tested up to: 6.7
Stable tag: 1.2.6
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
   The plugin preemptively blocks all the scripts that might contain a string in the `src` attribute or in the inner HTML that matches the strings you can configure on [Avacy](https://avacy.eu) for each vendor.

Avacy is a SaaS-based CMP that helps websites manage user consents efficiently and in compliance with data protection regulations. The platform offers customizable consent forms and integrates seamlessly with various web properties.

## Installation

Download: Download the plugin ZIP file from the WordPress Plugin Repository.
Upload: Upload the ZIP file to your WordPress site through the admin interface or via FTP.
Activate: Activate the plugin through the 'Plugins' menu in WordPress.

## Usage

### Cookie Banners:

Cookie banners will automatically appear on your site once configured.
Users can manage their consent preferences through these banners.

### Tracker Control:

Trackers are preemptively blocked based on a blacklist JSON file hosted on an AWS content delivery network.
Every time a page in the website is loaded, Avacy checks if scripts injected in the current page have `src` attribute or the `innerHTML` property containing a string matching the patterns in the blacklist and, if so, overrides some attributes and blocks the script.
Every script blocked this way will be launched only if user grants consent to the corresponding vendor and purposes by opting-in from the first layer or by selecting the vendor and its purposes from the second layer (the preference center).

#### CDN Information

The CDN used by Avacy is CloudFront, pointing to the following S3 bucket:

`https://avacy-cdn.s3.eu-central-1.amazonaws.com/`

`https://assets.avacy-cdn.com/`

**Integration Example**
Each custom vendor list can be reached using the following format:

`https://assets.avacy-cdn.com/config/{avacy_team_name}/{avacy_webspace_key}/custom-vendor-list.json;`

**Explanation**

**Base Url**: The base URL for the configuration file is https://assets.avacy-cdn.com/config/.
**`avacy_team_name`**: This option should be replaced with your specific tenant identifier. This is a unique identifier for your organization within Avacy.
**`avacy_webspace_key`**: This option should be replaced with your specific webspace key. This is a unique identifier for your specific webspace within your tenant's account.

**Example URL**
Assuming the following:

Tenant ID: _tenant123_
Webspace Key: _webspace456_
The URL to access the custom vendor list would be:

`https://assets.avacy-cdn.com/config/tenant123/webspace456/custom-vendor-list.json`

**Account Requirements**
To connect to and utilize Avacy's services, you need an active Avacy account with valid credentials, which include:

**Tenant ID**: Provided upon registration with Avacy.
**Webspace Key**: Generated after creating a new web space on Avacy.
Ensure you have these credentials available to correctly generate the URLs needed for integration.

### Avacy Consent Solution API

The plugin provides an interface that allows connecting to your consent archive on Avacy. To do this, it's necessary to generate a token from the Avacy platform and insert it into the Token field in the API token tab. This way, all the forms present on the page will be visible, and it will be possible to select various options, including:

- the ability to store consents given for that specific form in the consent archive;
- the fields to store for that form;
- a field to identify the user who has given the consent to be stored in the archive.

Everytime a user submits his data from a contact form, Avacy will perform a POST request towards Avacy Consent API, using the previously generated token as authenticator.

#### Under What Circumstances is Avacy Used?

Avacy is utilized whenever users provide consent through forms or interactions on your WordPress site. The plugin sends this consent data to Avacy's servers for storage and processing.

#### Avacy Service Link

For more information about Avacy and its features, visit Avacy's [website](https://avacysolution.com/).

#### Avacy Terms of Use and Privacy Policies

Avacy uses 3rd party services as the Avacy REST API and AWS distributions to provide assets as the blacklist JSON file.
Before integrating Avacy with this plugin, please review the Avacy [Terms of use](https://avacy.eu/terms-and-conditions) and [Privacy Policy](https://api.avacy.eu/jumpgroup/privacypolicy/14/it) to understand how your data is handled and what responsibilities you have as a user of their service.

We welcome your feedback and suggestions to improve the functionality of the plugin.

## Disclosure of Third-Party Service Usage

Our plugin utilizes third-party services to enhance its functionality. Below, we detail the specific circumstances under which these services are used, along with links to the respective service providers, their terms of use, and privacy policies.

### Services Utilized

**Amazon Web Services (AWS)** is a leading cloud platform offering a wide range of services, including computing power, storage, and networking, as well as advanced technologies like machine learning and IoT. AWS is designed for flexibility, scalability, and service reliability.

**AWS CloudFront** is a fast content delivery network (CDN) service that securely delivers data, videos, applications, and APIs to users globally with low latency and high transfer speeds.

> The URLs *https://jumpgroup.avacy-cdn.com* and *https://assets.avacy-cdn.com* are custom domains provided to access the AWS CloudFront service. Each asset is fetched from these URLs.

**Script Enqueuing**
Script enqueuing involves dynamically adding the main JavaScript core in order to display the cookie banner on your page. This method ensures that scripts are loaded only when needed, reducing page load times and improving performance.

**Configuration Fetching**
CMP configuration fetching is used to dynamically load the latest CMP configuration settings and vendor lists from a remote server. This process ensures that the plugin operates with the most current data, improving security, compatibility, and functionality.

#### Service Provider Information

**Service URL**: [Amazon Web Services](https://aws.amazon.com/)
**Privacy Policy**: [AWS Privacy Policy](https://d1.awsstatic.com/legal/privacypolicy/AWS%20Privacy%20Notice%20-%202024-01-01_IT.pdf)
**Terms of Use**: [AWS Terms of Use](https://aws.amazon.com/it/service-terms/)

**Legal Protection**
For your protection and to ensure transparency, we disclose the reliance on third-party services within our plugin. The URLs above provide detailed information on the terms of use and privacy policies of AWS. Users are encouraged to review these documents to understand the terms and conditions under which these third-party services operate.

By using our plugin, you acknowledge and agree to these terms and conditions. If you have any questions or concerns, please refer to the provided links or contact us directly for more information.

## License

This WordPress Consent Solution Plugin is licensed under the [GNU General Public License v2.0 or later](https://www.gnu.org/licenses/gpl-2.0.html).
