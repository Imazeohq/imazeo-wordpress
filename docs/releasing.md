# Publishing Imazeo

The intended WordPress.org slug and text domain are both `imazeo`. WordPress.org assigns the final slug during review. The readme lists `imazeo` as the WordPress.org contributor. Update `Tested up to` after the integration matrix passes on a newer WordPress release series. [Plugin readme handbook](https://developer.wordpress.org/plugins/wordpress-org/how-your-readme-txt-works/)

1. Keep the version in `imazeo.php`, `readme.txt`, the changelog and the translation template aligned.
2. Run CI and download its installable ZIP. Confirm that it extracts into `imazeo/`, then install it on a disposable site and complete the Media Library workflow with mocks. Check that Plugin Check passes with the final listing metadata before publishing.
3. After maintainer approval, make the Git tag `vX.Y.Z` on the reviewed commit and use that exact ZIP as the GitHub release asset.
4. Submit the ZIP for WordPress.org review. Once approved, use the **WordPress.org SVN** workflow on the approved version tag. Leave the commit control clear for the SVN dry run. After approval, enable the commit on an authorized run.

The workflow stages files with an explicit allowlist and passes the directory to the WordPress.org deploy action. Configure the `wordpress-org` GitHub environment with `SVN_USERNAME` and the WordPress.org SVN application password `SVN_PASSWORD`. Restrict that environment to release tags and the authorized release reviewers. The assets in `.wordpress-org` go to SVN's top-level `assets` directory.

For a manual SVN release, copy the staged `build/imazeo/` contents into `trunk/`, copy the marketplace icons from `.wordpress-org/` into `assets/`, review the SVN diff, and use `svn copy trunk tags/X.Y.Z`. Commit reviewed SVN changes with the assigned account. [WordPress.org SVN guide](https://developer.wordpress.org/plugins/wordpress-org/how-to-use-subversion/) · [Directory assets](https://developer.wordpress.org/plugins/wordpress-org/plugin-assets/)
