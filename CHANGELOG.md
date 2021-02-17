# Changelog

## 1.0.5 - 2021-02-17

* Fix validation errors on hidden input field (thanks, colak).

## 1.0.4 - 2021-02-16

* Permit file sizes to be specified using shorthand (10m, 60k, 2g, etc).
* Add hidden MAX_FILE_SIZE input field in the vain hope that some PHP systems will detect size violations prior to upload.
* Report correct maximum size in force on server-side errors.
* Remove bogus minlength/maxlength attributes to fix validation (thanks, colak).

## 1.0.3 - 2019-10-10

* Attempt to catch submission errors when the server empties out POST data for unknown reasons.
* Add an extra separator before text/plain content to help some mail programs display the first line of the message properly.
* Fix use of an unset variable.
* Remove a redundant line of code.

## 1.0.2 - 2018-09-13

* Fix empty (0-byte) files received by some email clients.
* Don't assume jQuery is loaded on public site (thanks, Gallex and colak).

## 1.0.1 - 2017-12-07

* Fix 'Multiple or malformed newlines' error during delivery.
* Add composer/packagist furniture (thanks, philwareham).

## 1.0.0 - 2017-07-19

* Initial release.
