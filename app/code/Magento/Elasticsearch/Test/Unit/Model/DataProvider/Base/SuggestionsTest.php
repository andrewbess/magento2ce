<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Magento\Elasticsearch\Test\Unit\Model\DataProvider\Base;

use Elasticsearch\Common\Exceptions\BadRequest400Exception;
use Magento\Elasticsearch\Model\Adapter\FieldMapper\Product\FieldProviderInterface;
use Magento\Elasticsearch\Model\Config;
use Magento\Elasticsearch\Model\DataProvider\Base\Suggestions;
use Magento\Elasticsearch\Model\DataProvider\Suggestions as SuggestionsDataProvider;
use Magento\Elasticsearch\SearchAdapter\ConnectionManager;
use Magento\Elasticsearch\SearchAdapter\SearchIndexNameResolver;
use Magento\Elasticsearch6\Model\Client\Elasticsearch;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Search\Request\Builder;
use Magento\Framework\Search\RequestInterface;
use Magento\Framework\Search\ResponseInterface;
use Magento\Framework\Search\SearchEngineInterface;
use Magento\Framework\TestFramework\Unit\Helper\ObjectManager as ObjectManagerHelper;
use Magento\Search\Model\QueryInterface;
use Magento\Search\Model\QueryResult;
use Magento\Search\Model\QueryResultFactory;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\StoreManagerInterface as StoreManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class SuggestionsTest extends TestCase
{
    /**
     * @var SuggestionsDataProvider
     */
    private $model;

    /**
     * @var Config|MockObject
     */
    private $config;

    /**
     * @var QueryResultFactory|MockObject
     */
    private $queryResultFactory;

    /**
     * @var ConnectionManager|MockObject
     */
    private $connectionManager;

    /**
     * @var ScopeConfigInterface|MockObject
     */
    private $scopeConfig;

    /**
     * @var SearchIndexNameResolver|MockObject
     */
    private $searchIndexNameResolver;

    /**
     * @var StoreManager|MockObject
     */
    private $storeManager;

    /**
     * @var FieldProviderInterface|MockObject
     */
    private $fieldProvider;

    /**
     * @var LoggerInterface|MockObject
     */
    private $logger;

    /**
     * @var Elasticsearch|MockObject
     */
    private $client;

    /**
     * @var QueryInterface|MockObject
     */
    private $query;

    /**
     * @var Builder|MockObject
     */
    protected $requestBuilder;

    /**
     * @var SearchEngineInterface|MockObject
     */
    protected $searchEngine;

    /**
     * Set up test environment
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->config = $this->getMockBuilder(Config::class)
            ->disableOriginalConstructor()
            ->setMethods(['isElasticsearchEnabled'])
            ->getMock();

        $this->queryResultFactory = $this->getMockBuilder(QueryResultFactory::class)
            ->disableOriginalConstructor()
            ->setMethods(['create'])
            ->getMock();

        $this->connectionManager = $this->getMockBuilder(ConnectionManager::class)
            ->disableOriginalConstructor()
            ->setMethods(['getConnection'])
            ->getMock();

        $this->scopeConfig = $this->getMockBuilder(ScopeConfigInterface::class)
            ->disableOriginalConstructor()
            ->getMockForAbstractClass();

        $this->searchIndexNameResolver = $this
            ->getMockBuilder(SearchIndexNameResolver::class)
            ->disableOriginalConstructor()
            ->setMethods(['getIndexName'])
            ->getMock();

        $this->storeManager = $this->getMockBuilder(StoreManager::class)
            ->disableOriginalConstructor()
            ->getMockForAbstractClass();

        $this->fieldProvider = $this->getMockBuilder(FieldProviderInterface::class)
            ->disableOriginalConstructor()
            ->getMockForAbstractClass();

        $this->logger = $this->getMockBuilder(LoggerInterface::class)
            ->disableOriginalConstructor()
            ->getMockForAbstractClass();

        $this->client = $this->getMockBuilder(Elasticsearch::class)
            ->disableOriginalConstructor()
            ->getMock();

        $this->query = $this->getMockBuilder(QueryInterface::class)
            ->disableOriginalConstructor()
            ->getMockForAbstractClass();

        $this->requestBuilder = $this->getMockBuilder(Builder::class)
            ->disableOriginalConstructor()
            ->getMock();

        $this->searchEngine = $this->getMockBuilder(SearchEngineInterface::class)
            ->disableOriginalConstructor()
            ->getMockForAbstractClass();

        $objectManager = new ObjectManagerHelper($this);

        $this->model = $objectManager->getObject(
            Suggestions::class,
            [
                'queryResultFactory' => $this->queryResultFactory,
                'connectionManager' => $this->connectionManager,
                'scopeConfig' => $this->scopeConfig,
                'config' => $this->config,
                'searchIndexNameResolver' => $this->searchIndexNameResolver,
                'storeManager' => $this->storeManager,
                'fieldProvider' => $this->fieldProvider,
                'logger' => $this->logger,
                'requestBuilder' => $this->requestBuilder,
                'searchEngine' => $this->searchEngine,
            ]
        );
    }

    /**
     * Test get items process with search suggestions disabled.
     * @return void
     */
    public function testGetItemsWithDisabledSearchSuggestion(): void
    {
        $this->scopeConfig->expects($this->once())
            ->method('isSetFlag')
            ->willReturn(false);

        $this->scopeConfig->expects($this->never())
            ->method('getValue');

        $this->config->expects($this->once())
            ->method('isElasticsearchEnabled')
            ->willReturn(true);

        $this->logger->expects($this->never())
            ->method('critical');

        $this->queryResultFactory->expects($this->never())
            ->method('create');

        $this->assertEmpty($this->model->getItems($this->query));
    }

    /**
     * Test get items process with search suggestions enabled.
     * @return void
     */
    public function testGetItemsWithEnabledSearchSuggestion(): void
    {
        $this->prepareSearchQuery();
        $this->client->expects($this->once())
            ->method('query')
            ->willReturn([
                'suggest' => [
                    'phrase_field' => [
                        [
                            'options' => [
                                'suggestion' => [
                                    'text' => 'query',
                                    'score' => 1
                                ]
                            ]
                        ]
                    ],
                ],
            ]);

        $this->logger->expects($this->never())
            ->method('critical');

        $query = $this->getMockBuilder(QueryResult::class)
            ->disableOriginalConstructor()
            ->getMock();

        $this->queryResultFactory->expects($this->once())
            ->method('create')
            ->with(['queryText' => 'query', 'resultsCount' => 5])
            ->willReturn($query);

        $this->assertEquals([$query], $this->model->getItems($this->query));
    }

    /**
     * Test get items process when throwing an exception.
     * @return void
     */
    public function testGetItemsException(): void
    {
        $this->prepareSearchQuery();
        $exception = new BadRequest400Exception();

        $this->client->expects($this->once())
            ->method('query')
            ->willThrowException($exception);

        $this->logger->expects($this->once())
            ->method('critical')
            ->with($exception);

        $this->queryResultFactory->expects($this->never())
            ->method('create');

        $this->assertEmpty($this->model->getItems($this->query));
    }

    /**
     * Prepare Mocks for default get items process.
     * @return void
     */
    private function prepareSearchQuery(): void
    {
        $storeId = 1;

        $this->scopeConfig->expects($this->exactly(2))
            ->method('isSetFlag')
            ->willReturn(true);

        $this->scopeConfig->expects($this->once())
            ->method('getValue')
            ->willReturn(1);

        $this->config->expects($this->once())
            ->method('isElasticsearchEnabled')
            ->willReturn(true);

        $store = $this->getMockBuilder(StoreInterface::class)
            ->disableOriginalConstructor()
            ->getMockForAbstractClass();

        $store->expects($this->once())
            ->method('getId')
            ->willReturn($storeId);

        $this->storeManager->expects($this->once())
            ->method('getStore')
            ->willReturn($store);

        $this->searchIndexNameResolver->expects($this->once())
            ->method('getIndexName')
            ->with($storeId, Config::ELASTICSEARCH_TYPE_DEFAULT)
            ->willReturn('magento2_product_1');

        $this->query->expects($this->once())
            ->method('getQueryText')
            ->willReturn('query');

        $this->fieldProvider->expects($this->once())
            ->method('getFields')
            ->willReturn([]);

        $this->connectionManager->expects($this->once())
            ->method('getConnection')
            ->willReturn($this->client);

        $request = $this->getMockBuilder(RequestInterface::class)
            ->disableOriginalConstructor()
            ->getMockForAbstractClass();

        $response = $this->getMockBuilder(ResponseInterface::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['count'])
            ->getMockForAbstractClass();

        $response->expects($this->any())
            ->method('count')
            ->willReturn(5);

        $this->requestBuilder->expects($this->any())
            ->method('setRequestName')
            ->with('quick_search_container')
            ->willReturnSelf();

        $this->requestBuilder->expects($this->any())
            ->method('bind')
            ->with('search_term', 'query')
            ->willReturnSelf();

        $this->requestBuilder->expects($this->any())
            ->method('create')
            ->willReturn($request);

        $this->searchEngine->expects($this->any())
            ->method('search')
            ->with($request)
            ->willReturn($response);
    }
}
