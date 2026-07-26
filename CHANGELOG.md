# Changelog

All notable changes to FastMagento are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [2.3.0]

### Added
- GraphQL OpenSearch serving layer. The storefront GraphQL `products` query now
  hydrates its result set from OpenSearch instead of MySQL, removing the per-item
  catalog-rule and tier-price N+1 while returning a result set identical to native
  Magento. Full-text search is routed through the same InstantSearch pipeline the
  storefront uses, so GraphQL relevance matches on-site search; price and name
  sorting are mapped accordingly.
- GraphQL layered-navigation aggregations served from OpenSearch facets, including
  a price-range facet. Aggregations are built directly from InstantSearch facet
  data, bypassing the core attribute aggregation builder so OpenSearch-native
  (non-EAV) facets no longer fatal the query.

### Notes
- Controlled by `fastmagento/graphql/os_serve_products` and
  `fastmagento/graphql/os_serve_search`, both on by default. Every GraphQL read
  falls back to native Magento on a miss or an OpenSearch outage, consistent with
  the rest of the module.
