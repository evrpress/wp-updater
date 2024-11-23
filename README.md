# WP Updater

The [WP Updater](https://github.com/evrpress/wp-updater) is a PHP library designed to facilitate automatic updates for WordPress plugins and themes hosted on GitHub. By integrating this library into your WordPress project, you can streamline the maintenance process and ensure that your plugins and themes receive updates directly from their GitHub repositories.

## Key Features

- **Automatic Updates:** Enables seamless updates for plugins and themes hosted on GitHub.
- **Readme Parsing:** Includes a `ReadmeParser.php` file to handle the parsing of readme files, ensuring that update information is accurately displayed.
- **Composer Support:** Provides a `composer.json` file, allowing for easy installation and management of dependencies via Composer.

## Installation and Usage

1. **Include the Library:** Add the WP Updater library to your plugin or theme by including the `WPUpdater.php` file.

Add this line to your `composer.json` file:

```json
{
 "require": {
  "evrpress/wp-updater": "^0.1.2"
 }
}

```

or run

```
composer require evrpress/wp-updater
```

And this to your base plugin file:

```php
require_once __DIR__ . '/vendor/autoload.php';


// Initialize the updater
class_exists( 'EverPress\WPUpdater' ) && \EverPress\WPUpdater::add(
 '{plugin-slug}',
 array(
  'repository' => '{plugin-owner}/{plugin-repo}',
 )
);
```

2. **Initialize the Updater:** In your plugin or theme's main file, initialize the updater with the necessary parameters, such as the GitHub repository URL and access token if required.
3. **Configure Updates:** Set up the updater to check for updates at desired intervals, ensuring that your plugin or theme remains current with the latest changes from the GitHub repository.

For detailed instructions and code examples, refer to the repository's [README file](https://github.com/evrpress/wp-updater).

## Benefits

- **Efficiency:** Automates the update process, reducing manual intervention.
- **Security:** Ensures that your plugins and themes are up-to-date with the latest security patches.
- **User Experience:** Provides end-users with a seamless update experience, similar to plugins and themes hosted on the official WordPress repository.

## Why Use WP Updater?

By integrating the WP Updater into your WordPress projects, you can effectively maintain your plugins and themes, leveraging GitHub's version control and distribution capabilities.
