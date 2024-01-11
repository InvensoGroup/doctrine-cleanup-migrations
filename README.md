# Invenso DoctrineCleanupMigrations

## Getting started
This package is used to clean up your migrations locally by compressing it to one file and deleting all references but the two newly created ones.

### Installation
To install this package run:
```shell
$ composer require invenso/doctrine-cleanup-migrations
```
### Usage
After installation run:
```shell
$ php bin/console invenso:cleanup:migrations
```
and follow the logging closely.

### Pending migration check
Before deleting everything the cleanup script will check if there are still migrations pending. If that's the case you need to make sure those will be either applied or discarded before being able to proceed.

Run:
```shell
$ php bin/console doctrine:migrations:status
``` 
To see more information about the pending versions.

### After cleanup migration
After the cleanup you'll get the option to migrate your newly cleaned migrations. The default option is "N" but if you fill out "Y" you'll have to confirm this 2-3 more times.

You can also choose to run the migrations manually:

```shell
$ php bin/console doctrine:migrations:migrate
```