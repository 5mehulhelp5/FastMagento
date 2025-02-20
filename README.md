# FastMagento: OpenSearch-Powered Magento 2 Search & PLP Optimization

## Overview
FastMagento replaces Magento's default ORM-based search, PLP, and PDP queries with OpenSearch, significantly improving performance and user experience. It provides an admin interface for configuring search attributes, layered navigation filters, and sorting options.

## Features
✅ **Full OpenSearch Integration** - Replaces Magento ORM for PLP, PDP, and Search  
✅ **Real-Time AJAX Search** - Updates results dynamically  
✅ **Faceted Navigation & Sorting** - Instant filter & sort updates  
✅ **Optimized for BreezeFront** - Fully compatible with Swissup's lightweight framework  
✅ **SEO-Optimized** - Structured data for better search engine visibility  
✅ **Full Cache Support** - Pre-caches frequently searched queries

---

# Installation Guide

## **1️⃣ Install the Module**

### **Option 1: Composer Installation (Recommended)**
```bash
composer require parkktech/fastmagento
bin/magento module:enable ParkkTech_FastMagento
bin/magento setup:upgrade
bin/magento cache:flush
```

### **Option 2: Manual Installation**
```bash
mkdir -p app/code/ParkkTech/FastMagento
cp -R /your/local/module/path/* app/code/ParkkTech/FastMagento/
```
Then, enable the module:
```bash
bin/magento module:enable ParkkTech_FastMagento
bin/magento setup:upgrade
bin/magento cache:flush
```

---

## **2️⃣ Configure OpenSearch in Magento Admin**
### Navigate to:
📍 `Stores` ➝ `Configuration` ➝ `FastMagento`

### **Set Up Indexing:**
- ✅ **Enable Real-Time Indexing:** Yes
- ✅ **Enable Cron-Based Indexing:** Yes
- ✅ **Set OpenSearch Host** (Your OpenSearch URL)

### **Search & Filter Configuration:**
- ✅ **Select Search Attributes**
- ✅ **Select Layered Navigation Filters**
- ✅ **Define Sorting Options**
- ✅ **Enable AJAX Search & Filtering**

---

## **3️⃣ Verify OpenSearch Indexing**
### **Run a full reindex:**
```bash
bin/magento indexer:reindex fastmagento_product_indexer
```
### **Check OpenSearch for a basic search:**
```bash
curl -X GET "http://localhost:9200/magento_products/_search?pretty"
```
✅ **If products appear in JSON format, indexing is working!**

---

### **Additional OpenSearch Commands**

**1) Count Documents**
```bash
curl -X GET "http://localhost:9200/magento2_her_production_products/_count?pretty"      -H 'Content-Type: application/json'      -d '{
       "query": {
         "match_all": {}
       }
     }'
```
Example output:
```json
{
  "count" : 13863,
  "_shards" : {
    "total" : 1,
    "successful" : 1,
    "skipped" : 0,
    "failed" : 0
  }
}
```

**2) View Index Mapping**
```bash
curl -X GET "http://<host>:9200/<index-name>/_mapping?pretty"
curl -X GET "http://localhost:9200/magento2_her_production_products/_mapping?pretty"
```

**3) View All Documents**
```bash
curl -X GET "http://localhost:9200/magento2_her_production_products/_search?pretty&size=10000"      -H "Content-Type: application/json"      -d '{
       "query": {
         "match_all": {}
       }
     }'
```

**4) Retrieve a Single Document by ID**
```bash
curl -X GET "http://localhost:9200/magento2_her_production_products/_doc/965577?pretty"
```
If `_id` is `965577`, the response looks like:
```json
{
  "_index" : "magento2_her_production_products",
  "_id" : "965577",
  "_source" : {
    "entity_id" : 965577,
    "sku" : "nau001-wr43s5",
    ...
  }
}
```

**5) Filter by `entity_id`**
```bash
curl -X GET "http://localhost:9200/magento2_her_production_products/_search?pretty"      -H "Content-Type: application/json"      -d '{
       "query": {
         "term": {
           "entity_id": "965577"
         }
       }
     }'
```

**6) Filter by `sku`**
```bash
curl -X GET "http://localhost:9200/magento2_her_production_products/_search?pretty"      -H "Content-Type: application/json"      -d '{
       "query": {
         "term": {
           "sku": "nau001-wr43s5"
         }
       }
     }'
```

**7) Search Partial SKUs (Wildcard)**
```bash
curl -X GET "http://localhost:9200/magento2_her_production_products/_search?pretty"      -H "Content-Type: application/json"      -d '{
       "query": {
         "wildcard": {
           "sku": "*nau001-wr*"
         }
       }
     }'
```

---

## **4️⃣ Validate Frontend (PLP, PDP, Search)**

- ✅ Ensure products load from OpenSearch
- ✅ Confirm no ORM queries in DevTools (`F12`)
- ✅ Test AJAX filtering & sorting

---

## **5️⃣ Performance & Caching**
```bash
bin/magento setup:static-content:deploy -f
bin/magento cache:flush
```

---

## 🎉 FastMagento is Now Fully Installed!
✅ **Magento ORM is fully removed from Search, PLP, and PDP**  
✅ **OpenSearch powers all product queries**  
✅ **AJAX-based filtering & sorting work smoothly**  
✅ **BreezeFront compatibility ensures maximum performance**  
