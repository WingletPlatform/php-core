# Route configuration

This document describes how route configuration works.

Controller related routing is explaned in [Development/Routes](../Development/Controllers.md)

---

## Base url

### Based on Traefik header

If you have enabled `X-Forwarded-Prefix` header in Traefik and you leave base url empty in configuration,
base url will be automatically detected from `X-Forwarded-Prefix` header.

Enabling `X-Forwarded-Prefix` header for application in Traefik:
```services:
  my-app:
    image: your-app-image
    labels:
      - "traefik.enable=true"
      # Määrittele reitityssääntö (esim. polku /api)
      - "traefik.http.routers.my-app.rule=Host(`example.com`) && PathPrefix(`/api`)"
      - "traefik.http.routers.my-app.entrypoints=web"
      
      # Aktivoi StripPrefix-middleware (tämä lisää X-Forwarded-Prefix otsikon)
      - "traefik.http.routers.my-app.middlewares=my-stripprefix"
      - "traefik.http.middlewares.my-stripprefix.stripprefix.prefixes=/api"
```

### Manual

You can set base url manually in configuration. This is done in `http` section:

```php
  'http' => [
    // mount prefix, esim "/api/customers" tai "" tai "/"
    // jos tyhjä, yritetään auto-detect headerista / server-muuttujista
    'base_path' => '',

    // Traefik: usein X-Forwarded-Prefix on käytössä (voi myös olla pois)
    'trust_forwarded_prefix' => true,
  ],
```

