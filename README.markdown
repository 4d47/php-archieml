
# ArchieML

Parse Archie Markup Language ([ArchieML v0.4.2](https://github.com/newsdev/archieml-js/blob/e0fab24/archieml.js)) documents into PHP arrays.

Read about the ArchieML specification at [archieml.org](http://archieml.org).

## Install

`composer require 4d47/archieml`

## Usage

```php
ArchieML::load("key: value"); // [ 'key' => 'value' ]
ArchieML::load(fopen('my-file.aml', 'r'));
```

## Test
```sh
git submodule init
git submodule update
composer install
composer test
```
