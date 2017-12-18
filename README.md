Configuration Notices for Bolt
==============================

Friendly helpers for your Bolt installation. This extension comes bundled with
the packaged distribution version of Bolt (commonly known as "The `tar` or `zip`
version"). This extension provides helpful tips to prevent common pitfalls, in a
range of different situations.

To install this extension in your custom bootstrapped version of Bolt, run the
following in the root folder with your main `composer.json`:

```
composer require bolt/configuration-notices
```

![7e22d8c8-bbdd-11e6-9668-48a1ec86ba04](https://cloud.githubusercontent.com/assets/1833361/21287029/3f4d8cf4-c463-11e6-8cd1-69583e7fa2ba.png)

You can influence a few of the checks through the configuration. How meta!

```yaml
debug_local_domains: [ '.localhost' ]

configuration_notices:
    log_threshold: 1000
```

 - `local_domains` can be set to contain (parts of) domain names, and is used
   in the check to determine if the current installation is considered to be
   "local" or "production".
 - `log_threshold` is used to determine what is considered 'a lot of rows' in
   the checks for the Change log and System log, to give a suggestion to trim
   the database tables.
