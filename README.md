# Beacon — Grav SEO Plugin

A Grav CMS plugin for managing SEO meta tags, Open Graph, Twitter Cards, and Schema.org JSON-LD structured data from the admin panel.

Originally based on [grav-plugin-seo](https://github.com/paulmassen/grav-plugin-seo) by Paul Massendari.

## Table of Contents

* [Features](#features)
* [Requirements](#requirements)
* [Installation](#installation)
* [Configuration](#configuration)
* [Usage](#usage)
* [Contributing](#contributing)
* [License](#license)

## Features

- **Google** — customize your page title and meta description as they appear in search results
- **Twitter Cards** — control title, description, and image when shared on Twitter/X
- **Open Graph** — control appearance on Facebook, LinkedIn, and other OG-aware platforms; includes `og:locale` from your Grav language settings
- **Canonical URL** — automatically injects `<link rel="canonical">` on every page
- **JSON-LD Structured Data** — generate Schema.org microdata for:
  - Article
  - BreadcrumbList (automatic, from page ancestry)
  - Event
  - MusicEvent
  - Organization
  - Person
  - Product
  - Restaurant
  - Custom JSON

### Example JSON-LD output

```json
{
    "@context": "https://schema.org",
    "@type": "Article",
    "headline": "Article Title",
    "mainEntityOfPage": {
        "@type": "WebPage",
        "url": "https://yourwebsite.com"
    },
    "articleBody": "Lorem Ipsum dolor sit amet",
    "datePublished": "2017-12-01T00:00:00+00:00",
    "dateModified": "2019-01-01T00:00:00+00:00",
    "description": "Description of the article",
    "author": "Steve Jobs",
    "publisher": {
        "@type": "Organization",
        "name": "Apple",
        "logo": {
            "@type": "ImageObject",
            "url": "https://yourwebsite.com/home/logo.png",
            "width": "200",
            "height": "100"
        }
    },
    "image": {
        "@type": "ImageObject",
        "url": "https://yourwebsite.com/home/myimage.jpg",
        "width": "800",
        "height": "600"
    }
}
```

## Requirements

- Grav >= 1.7.0
- Admin plugin >= 1.10.0
- Your base template must include the metadata partial: `{% include 'partials/metadata.html.twig' %}`
- The SEO tab extends the default blueprint — ensure your page blueprint includes `extends@: default`

## Installation

Install manually by cloning or downloading this repository into:

    /your/site/grav/user/plugins/seo

The plugin injects all meta tags and structured data automatically. No template modifications required.

## Configuration

Open **Plugins > Beacon** in the admin panel to:

- Set your Facebook App ID and Twitter handle
- Enable or disable individual microdata types

Per-page settings are managed from the **SEO** tab on each page editor.

## Usage

The Beacon plugin adds an SEO tab to every page in the admin. From there you can:

- Preview and customize how the page appears in Google search results
- Set Twitter Card and Open Graph metadata
- Enable and populate any supported Schema.org structured data type

All output is injected directly into `<head>` by the plugin — no Twig template changes needed.

## Contributing

Issues and pull requests welcome at [github.com/ccmmgg/grav-plugin-seo](https://github.com/ccmmgg/grav-plugin-seo/issues).

Please search existing issues before opening a new one.

## License

MIT — see [LICENSE](LICENSE).
Originally based on work copyright 2020 Paul Massendari.
