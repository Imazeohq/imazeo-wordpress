# Security

Report issues through the repository’s private vulnerability reporting page. Include the plugin, WordPress and PHP versions, reproduction steps using synthetic data, and the impact. [Report privately](https://github.com/Imazeohq/imazeo-wordpress/security/advisories/new)

Keep production project keys out of issues, logs, fixtures and screenshots. Rotate an exposed key in the Imazeo customer portal and reconnect affected sites. A saved credential uses authenticated encryption derived from the site's WordPress authentication key. A user with access to the WordPress runtime and its configuration can use the credential. [Customer portal](https://app.imazeo.com/)

Users with media upload permission can approve requests that use the project’s credits. Local sources also require attachment edit permissions and visibility checks. Site administrators can view project files, outputs, dynamic QR settings and analytics. Files sent to Imazeo may be retained there. Importing project media into the local library is an explicit decision subject to the site's access rules. [Privacy notice](https://imazeo.com/legal/privacy/)
