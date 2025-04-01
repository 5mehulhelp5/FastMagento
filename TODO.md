## This is to keep track of whats pending

### Product

### ** For the product data we run the indexer we Run a full reindex:**
```bash
bin/magento indexer:reindex fastmagento_product_indexer
```
This pushes the Product data to OpenSearch.

with this code  
```bash
app/code/ParkkTech/FastMagento/Model/Indexer/ProductIndexer.php
```

You can view the entire index by 
```bash
curl -X GET "http://localhost:9200/magento_products/_search?pretty"
```

Or by prduct id:
```bash
curl -X GET "http://localhost:9200/{INDEX NAME}/_doc/{PRUCT ID NUMBER}?pretty""
```

To work on this you try to load a product page 
the controller fires at
```bash
app/code/ParkkTech/FastMagento/Helper/ShellProductBuilder.php
```

And it will then grab the id fetch the data from open search then try to cast the OpenSearch results back to a magento product object
```bash
app/code/ParkkTech/FastMagento/Helper/ShellProductBuilder.php
```
This class still need some work getting all the product details properly cast into the object to be availaible through the registry.  

The image Gallery is not working properly yet and configurable product data is not yet being cast in properly. 

We will then want to Get Product Catalog Rules among other things in here. 

What I was going was having the db query logger enabled for all queries an having profile enabled so I can see the classes triggering all queries. 
Then one by one extendending those classes to pull from the registry not the DB.  


There is alot of unused code in this module at the moment because I tried many differnt approaches.  This current apprach seesm the be the way to make this work. So once we have a complete working products we can move to all the differnt product types then move on to the Category and then Search to see how we want to handle each of these.  


My initial though is if we can get this to work with PDPs flawlessly we can make a release of this extension to speed up PDP loading. I want to also release this on goRide. 

Then we can be monitoring and debuging the PDP functionality while we work on moving to the category level functionality. 


- Change 
-- vendor/magento/module-catalog/Model/Product.php::convertToMediaGalleryInterface
-- vendor/magento/module-catalog-inventory/Model/Plugin/AfterProductLoad.php::afterLoad
