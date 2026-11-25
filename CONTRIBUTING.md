# Contributing

Use PHP 8.2 or later. The runtime has no Composer or npm dependencies. Run the standalone tests and build the installable ZIP with PHP, Node and Python.

~~~sh
php tests/unit.php
node --check assets/media-picker.js
python scripts/package.py
~~~

GitHub Actions configures PHP syntax, coding and compatibility checks, Plugin Check on the staged plugin, and integration tests against disposable WordPress sites. Composer is used for development checks only.

On an existing disposable WordPress test installation, use WP-CLI to install and activate the ZIP. Run `wp eval-file /path/to/repository/tests/wordpress.php`. The test creates temporary users and attachments, intercepts Imazeo HTTP calls, and tests the worker and uninstall against the WordPress database. Use a fresh site because the test removes plugin settings.

Review the operation list, parameters and request formats when the production API changes. Download the public contract to a local file, run `python scripts/sync-contract.py /path/to/contract.json`, and review the catalog diff alongside the coverage matrix and mock tests. Use synthetic fixtures for tests involving credentials, uploads or credits. Keep interface translations and external service disclosures aligned with behavior. [Public developer contract](https://imazeo.com/openapi/imazeo-developer.json)
