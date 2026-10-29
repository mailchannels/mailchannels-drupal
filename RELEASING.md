# Maintainer and publication handoff

No versioned release, package registration or Drupal.org submission is authorized
by this candidate's existence. Support contact: dev@mailchannels.com.

1. Assign an individual code maintainer. Create their individual Drupal.org account
   and set up access under Drupal.org's current project contributor rules. A shared
   support email is not an individual maintainer identity.
2. Verify project/machine-name availability and ownership on Drupal.org before
   registering names. Review licensing, project-description and security-coverage
   requirements; do not claim advisory coverage without approval.
3. Complete the remaining scope
   in VALIDATION.md, and obtain implementation/security review.
4. Validate authorized provider behavior in an isolated site. Review sender-domain
   SPF/Domain Lockdown and uncertain acceptance handling. Keep credentials secret.
5. Finalize Composer/project naming, supported versions, packaging and upgrade/
   uninstall behavior. Tag only an approved version and verify a fresh installation.
6. Publish the Drupal.org project/release through the assigned maintainer. Verify
   public search/category visibility, Composer installation and support links.

References: https://www.drupal.org/docs/develop/git/setting-up-git-for-drupal
and https://www.drupal.org/docs/develop/managing-a-drupalorg-theme-module-or-distribution-project
