# Development functionalities 

This document describes how configuration works in development environments.

---

## Route configuration

### Enable route secret

To access special routes, like:
* POST: /__dev/routes/rebuild
* POST: /__dev/controllers/scan

You need to have development secret on HTTP request header `X-App-Secret`.

```php
<?php
'dev' => [
  'secret' => 'CHANGE_ME_LONG_RANDOM',
],
```



POST /__dev/controllers/scan
X-Dev-Secret: <dev.secret>
Content-Type: application/json

{
"namespacePrefix": "App\\",
"baseDir": "/var/www/App/src",
"subDir": "Controller"
}