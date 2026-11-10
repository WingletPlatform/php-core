# Updating routes 

This document describes how to work with routes while developing app running on Winglet.

---

## Route table update

Routes are stored in cache php files which needs to be updated after changes in controllers. You can
update routes by POST request or by running bin/generate-routes script.

### Update routes by POST request

```http request
POST /__dev/routes/rebuild
X-Dev-Secret: <dev.secret>
```

## Update controllers

Route table is updated from controller list to make route updates faster. When adding or removing controllers,
you need to update controllers list. You can do it by POST request.

### Update controllers by POST request

```http request
POST /__dev/controllers/scan
X-Dev-Secret: <dev.secret>
Content-Type: application/json

{
"namespacePrefix": "App\\",
"baseDir": "/var/www/App/src",
"subDir": "Controller"
}