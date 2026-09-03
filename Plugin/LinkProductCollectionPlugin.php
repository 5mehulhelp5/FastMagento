<?php

declare(strict_types=1);

namespace ParkkTech\FastMagento\Plugin;

use Magento\Catalog\Model\ResourceModel\Product\Link\Product\Collection;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\App\State;
use Magento\Framework\DataObject;
use ParkkTech\FastMagento\Helper\OpenSearchPdpFetcher;
use ParkkTech\FastMagento\Helper\ShellProductBuilder;
use ParkkTech\FastMagento\Helper\WriteLog;

/**
 * Serve Related / Up-sell / Cross-sell (product-link) collections from OpenSearch.
 *
 * The native link collection applies its parent+link-type filter lazily (in _renderFiltersBefore,
 * inside load()), so the collection's own getAllIds() runs UNFILTERED and returns every product —
 * unusable for a short-circuit. Instead we read the linked ids directly from catalog_product_link
 * (the link graph isn't indexed in OS), in position order, then hydrate each linked product from a
 * single OS mget. Each shell carries a url_data_object built from the indexed request_path, so
 * Product\Url::getUrl() takes the hasUrlDataObject() branch and never calls the url_rewrite finder
 * (the per-grid-item N+1 that first broke this).
 *
 * All-or-nothing: if the link query throws, or ANY visible linked product is missing from OS, it
 * falls back to the native load so a block never silently drops a merchant-linked product.
 */
class LinkProductCollectionPlugin
{
    /** Visibility ids that appear on storefront product grids (everything except "not visible"). */
    private const VISIBLE = [
        \Magento\Catalog\Model\Product\Visibility::VISIBILITY_IN_CATALOG,
        \Magento\Catalog\Model\Product\Visibility::VISIBILITY_IN_SEARCH,
        \Magento\Catalog\Model\Product\Visibility::VISIBILITY_BOTH,
    ];
    private const STATUS_ENABLED = 1;

    /** Magento link_type_id => the key ProductIndexer writes onto the document. */
    private const LINK_TYPE_KEYS = [
        \Magento\Catalog\Model\Product\Link::LINK_TYPE_RELATED => 'related',
        \Magento\Catalog\Model\Product\Link::LINK_TYPE_UPSELL => 'upsell',
        \Magento\Catalog\Model\Product\Link::LINK_TYPE_CROSSSELL => 'crosssell',
    ];

    /**
     * Per-request memo of the indexed link graph, parent id => fm_links array (or false when the
     * parent doc has no usable graph). A product page loads related AND up-sell as two separate
     * collections; without this each would re-fetch the same parent document.
     *
     * @var array<int, array<string, int[]>|false>
     */
    private array $linkGraphCache = [];

    public function __construct(
        private readonly State $appState,
        private readonly ResourceConnection $resource,
        private readonly OpenSearchPdpFetcher $fetcher,
        private readonly ShellProductBuilder $shellProductBuilder,
        private readonly WriteLog $writeLog
    ) {
    }

    /**
     * @param Collection $subject
     * @param callable $proceed
     * @return Collection
     */
    public function aroundLoad(Collection $subject, callable $proceed, $printQuery = false, $logQuery = false)
    {
        try {
            $parentId = $this->resolveParentId($subject);

            if ($subject->isLoaded()
                || $this->appState->getAreaCode() !== 'frontend'
                || !$subject->getLinkModel()
                || !$subject->getLinkModel()->getLinkTypeId()
                || !$parentId
            ) {
                return $proceed($printQuery, $logQuery);
            }

            $ids = $this->resolveLinkedIds(
                $parentId,
                (int) $subject->getLinkModel()->getLinkTypeId()
            );
            if ($ids === null) {
                return $proceed($printQuery, $logQuery);   // could not determine — native decides
            }
            if ($ids === []) {
                // The INDEX says this product has no links of this type. That is a real answer,
                // not a miss, so the collection is simply empty — returning $proceed() here is what
                // used to make every no-related-products PDP pay for a native EAV collection load
                // just to discover there was nothing to load.
                $this->markLoaded($subject, 0);

                return $subject;
            }

            $docs = $this->fetcher->fetchByIds($ids);

            // Display order of the MERCHANT'S set. Core returns the ids exactly as linked; a
            // companion module may plug into
            // orderForDisplay() and may re-order only — never add, never drop. The documents are
            // passed along because a re-order needs them and they are already in hand.
            $ids = $this->orderForDisplay($ids, $docs, $subject);

            $shells = [];
            foreach ($ids as $id) {                       // merchant position, or a decorated order
                $doc = $docs[$id] ?? null;
                if ($doc === null) {
                    return $proceed($printQuery, $logQuery);   // a linked product not in OS native
                }
                if (!in_array((int) ($doc['visibility'] ?? 0), self::VISIBLE, true)
                    || (int) ($doc['status'] ?? 0) !== self::STATUS_ENABLED) {
                    continue;                              // native block would also hide these
                }
                $shell = $this->shellProductBuilder->buildNoEavProductFromOsDoc($doc);
                if (!empty($doc['request_path'])) {
                    $shell->setData('url_data_object', new DataObject([
                        'url_rewrite' => $doc['request_path'],
                        'store_id' => $shell->getStoreId(),
                    ]));
                }
                $shells[] = $shell;
            }

            foreach ($shells as $shell) {
                $subject->addItem($shell);
            }
            $this->markLoaded($subject, count($shells));

            return $subject;
        } catch (\Throwable $e) {
            $this->writeLog->writeErrorLog(
                '[FastMagento] link-collection OS serve failed, using native: ' . $e->getMessage()
            );
            return $proceed($printQuery, $logQuery);
        }
    }

    /**
     * The order in which the merchant's linked ids are displayed.
     *
     * Core's answer is the merchant's own order, unchanged. This is a public seam: the
     * companion module may decorate it with an after-plugin that permutes the ids for the
     * current shopper. Whatever plugs in here must return a permutation of `$ids` — the set is the
     * merchant's and a row that has quietly lost or gained a product is a bug.
     *
     * @param int[] $ids merchant order, from catalog_product_link position
     * @param array<int, array<string, mixed>> $docs the OpenSearch documents for those ids
     * @return int[]
     */
    public function orderForDisplay(array $ids, array $docs, Collection $subject): array
    {
        return $ids;
    }

    /**
     * The product whose links are being loaded.
     *
     * `getProduct()` is the obvious answer and is null more often than not. Core sets `_product`
     * only in `setProduct()`; the path a product page actually takes CONSTRUCTS the collection with
     * its root product ids instead (see the `$productIds` constructor argument), so `_product` is
     * never assigned and `getProduct()` returns null for the whole life of the collection.
     *
     * Gating on `getProduct()` therefore meant this plugin never fired on a product page at all —
     * every related / up-sell / cross-sell block silently fell through to the native EAV load, and
     * the OpenSearch serving this class exists to do simply never happened. The symptom was a
     * product page costing ~20 product queries where a listing costs 1, with nothing failing.
     *
     * So fall back to the private `$productIds` the constructor filled. Those hold LINK FIELD
     * values, which equal `entity_id` on Community but are `row_id` on Commerce with staging —
     * and `catalog_product_link.product_id` is an entity_id. When the two differ we cannot safely
     * translate here, so we return null and let the native load handle it.
     *
     * @return int|null
     */
    private function resolveParentId(Collection $subject): ?int
    {
        $product = $subject->getProduct();
        if ($product && $product->getId()) {
            return (int) $product->getId();
        }

        try {
            // Reflection against the DECLARING class, not the object.
            // $subject is an ...\Interceptor subclass, and `Closure::call()` binds the closure's
            // scope to the object's class — a subclass, which by definition cannot read a private
            // parent property. It returns null silently, which reads exactly like "no ids set".
            $property = new \ReflectionProperty(Collection::class, 'productIds');
            $property->setAccessible(true);
            $ids = $property->getValue($subject);

            $linkField = \Closure::bind(
                function () {
                    return $this->getLinkField();
                },
                $subject,
                Collection::class
            )();
        } catch (\Throwable $e) {
            return null;
        }

        if ($linkField !== 'entity_id' || !is_array($ids) || count($ids) !== 1) {
            return null;
        }

        $parentId = (int) reset($ids);

        return $parentId > 0 ? $parentId : null;
    }

    /**
     * Linked product ids for a parent + link type, in position order, straight from the link
     * graph (catalog_product_link is not indexed in OpenSearch).
     *
     * @return int[]
     */
    /**
     * Mark the collection loaded AND tell it how many rows it has.
     *
     * Setting _totalRecords is not bookkeeping — it is the difference between one query and none.
     * AbstractDb::getSize() runs a COUNT whenever _totalRecords is null, and the related/up-sell
     * templates call getSize() to decide whether to render the block at all. So short-circuiting
     * load() alone still left a COUNT(DISTINCT e.entity_id) per block on every product page, and
     * it fired hardest in the empty case: a product with no related items skipped the load and
     * then counted the database to confirm there was nothing to show.
     *
     * Both values are protected on the collection, hence the bound closure.
     */
    private function markLoaded(Collection $subject, int $total): void
    {
        (function () use ($total) {
            $this->_setIsLoaded(true);
            $this->_totalRecords = $total;
        })->call($subject);
    }

    /**
     * Linked product ids in position order, or NULL when this plugin cannot answer and the
     * native load must run.
     *
     * An empty ARRAY and NULL mean different things and the caller depends on the difference:
     * [] is the index positively stating there are no links of this type (serve an empty
     * collection, cost nothing), NULL is "no usable indexed answer" (fall back to native).
     *
     * ProductIndexer projects the link graph onto the parent document (`fm_links`) precisely so
     * this read does not have to touch catalog_product_link. Reading it back off the parent doc
     * turns the last three catalogue queries on a product page into part of a document fetch the
     * serving layer was making anyway.
     *
     * Falls back to the DB whenever the field is missing — an index built before this field
     * existed, a parent that is not in OpenSearch, or any fetch error — so the graph is never
     * silently truncated. An indexed product that genuinely has no links of this type returns an
     * empty list from the index WITHOUT falling through, which is the point: no links must not
     * cost a query either.
     */
    private function resolveLinkedIds(int $parentId, int $linkTypeId): ?array
    {
        $key = self::LINK_TYPE_KEYS[$linkTypeId] ?? null;
        if ($key === null) {
            // Unknown link type — no indexed field to consult, so let native handle it entirely.
            return null;
        }

        if (!array_key_exists($parentId, $this->linkGraphCache)) {
            $graph = false;
            try {
                $doc = $this->fetcher->fetchPdpById($parentId);
                if (is_array($doc) && isset($doc['fm_links']) && is_array($doc['fm_links'])) {
                    $graph = $doc['fm_links'];
                }
            } catch (\Throwable $e) {
                $graph = false;   // fall through to the DB below
            }
            $this->linkGraphCache[$parentId] = $graph;
        }

        $graph = $this->linkGraphCache[$parentId];
        if ($graph === false || !array_key_exists($key, $graph)) {
            // No usable indexed graph. Fall back to the DB, and treat an empty DB result as
            // "nothing to serve" so the native load still runs and stays authoritative.
            $ids = $this->getLinkedIdsFromDb($parentId, $linkTypeId);

            return $ids ?: null;
        }

        return array_values(array_map('intval', (array) $graph[$key]));
    }

    private function getLinkedIdsFromDb(int $parentId, int $linkTypeId): array
    {
        $connection = $this->resource->getConnection();
        $select = $connection->select()
            ->from(['l' => $this->resource->getTableName('catalog_product_link')], ['linked_product_id'])
            ->joinLeft(
                ['la' => $this->resource->getTableName('catalog_product_link_attribute')],
                'la.link_type_id = l.link_type_id AND la.product_link_attribute_code = ' . $connection->quote('position'),
                []
            )
            ->joinLeft(
                ['ai' => $this->resource->getTableName('catalog_product_link_attribute_int')],
                'ai.product_link_attribute_id = la.product_link_attribute_id AND ai.link_id = l.link_id',
                ['position' => 'ai.value']
            )
            ->where('l.product_id = ?', $parentId)
            ->where('l.link_type_id = ?', $linkTypeId)
            ->order('position ' . \Magento\Framework\DB\Select::SQL_ASC)
            ->order('l.link_id ' . \Magento\Framework\DB\Select::SQL_ASC);

        return array_map('intval', $connection->fetchCol($select));
    }
}
