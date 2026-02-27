# Vercel WP

A modern WordPress plugin to manage **Deploy** and **Headless Preview** workflows with Vercel, directly from the WordPress admin.

> Third-party plugin (not official Vercel).

## Why Vercel WP

Vercel WP gives editors and developers a single place to:

- trigger deployments,
- preview content before publish,
- keep frontend URLs coherent in headless setups,
- run safe URL migrations from WordPress.

No dashboard hopping, no complex manual flow.

## Core Features

| Area | What you get |
|---|---|
| Deploy | One-click deploy trigger, deployment status, deployment history |
| Preview | Admin bar + editor preview buttons, split-screen preview tools |
| Options | Global display/headless toggles + template management |
| Page templates | Create/delete plugin-managed templates for pages |
| Headless URL mapping | WordPress URL -> frontend URL mapping |
| Cache/ISR | Revalidation trigger from WordPress |
| Migration tools | Massive URL replacement with preview + confirmation |
| ACF safety | Serialized ACF/meta replacement support |
| Diagnostics | Connection test + advanced diagnostics |

## Preview Modes

Vercel WP supports 2 frontend strategies:

### 1) `Static (Preview URL mapping)`
For static frontends (Astro, Gatsby, static builds, etc.).

- Uses preview URL + production URL mapping.
- Ideal when preview is tied to deployment URLs.

### 2) `Draft + Revalidate (frameworks SSR)`
For SSR/headless frontends using draft mode + cache revalidation (Next.js, Nuxt, other custom SSR stacks).

- Configure Draft endpoint and Revalidate endpoint.
- Configure query parameter names.
- Shared secret generated in BO and exposed as:

```env
HEADLESS_PREVIEW_SECRET=your_generated_secret
```

Use the same value in your frontend environment variables.

## Quick Start

### Installation

1. Upload `vercel-wp` to `/wp-content/plugins/`.
2. Activate the plugin in WordPress.
3. Open `Vercel WP` in WordPress admin.

The plugin now uses dedicated admin pages:

- `Vercel WP > Deploy`
- `Vercel WP > Preview`
- `Vercel WP > Options`

### Deploy Tab

Configure:

- Webhook Build URL
- Vercel Project ID
- Vercel API Key

Then trigger deployments from settings or admin bar.

### Preview Tab

1. Choose Preview mode (`Static` or `Draft + Revalidate`).
2. Configure required frontend URLs/endpoints.
3. Set production URL for mapping and permalink rewriting.
4. Test connection.

### Options Tab

Use `Options` to:

- configure global display/headless behavior,
- create page templates managed by the plugin,
- remove plugin templates with confirmation.

Templates created here are available in page editor under:

- `Page Attributes > Template`

## URL & Headless Management

When production URL is configured, Vercel WP can:

- rewrite WordPress permalinks toward frontend URL,
- update admin "Visit site" links,
- help redirect frontend-facing routes,
- assist mass URL replacement in content/options/meta.

## Requirements

- WordPress 5.0+
- PHP 8.0+
- Vercel project configured

## Security Notes

- Sensitive deploy settings are masked in admin.
- Nonce + capability checks are used on admin AJAX actions.
- URL validation is enforced on critical preview/revalidation operations.

## Changelog

See [CHANGELOG.md](CHANGELOG.md).

## Disclaimer

Vercel WP is not affiliated with, endorsed by, or supported by Vercel Inc.
Vercel is a trademark of Vercel Inc.

## Support

For issues and feature requests, use the project repository and include:

- WordPress version
- Plugin version
- Selected preview mode
- Steps to reproduce
