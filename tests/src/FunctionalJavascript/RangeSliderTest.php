<?php

namespace Drupal\Tests\search_api_range_filter\FunctionalJavascript;

use Drupal\entity_test\Entity\EntityTestMulRevChanged;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\FunctionalJavascriptTests\WebDriverTestBase;
use Drupal\search_api\Entity\Index;
use Drupal\Tests\search_api\Functional\ExampleContentTrait;
use Drupal\user\Entity\Role;
use Drupal\user\RoleInterface;
use Drupal\views\Entity\View;

/**
 * Tests the slider widget in the browser.
 *
 * @group search_api_range_filter
 */
class RangeSliderTest extends WebDriverTestBase {

  use ExampleContentTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'datetime',
    'entity_test',
    'field',
    'search_api',
    'search_api_db',
    'search_api_range_filter',
    'search_api_test_db',
    'search_api_test_example_content',
    'views',
  ];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->setUpExampleStructure();

    $index = Index::load('database_search_index');
    $fields_helper = $this->container->get('search_api.fields_helper');
    foreach (['date_start', 'date_end'] as $field_name) {
      FieldStorageConfig::create([
        'field_name' => $field_name,
        'entity_type' => 'entity_test_mulrev_changed',
        'type' => 'datetime',
        'settings' => ['datetime_type' => 'date'],
      ])->save();
      FieldConfig::create([
        'field_name' => $field_name,
        'entity_type' => 'entity_test_mulrev_changed',
        'bundle' => 'item',
      ])->save();
      $index->addField($fields_helper->createField($index, $field_name, [
        'label' => $field_name,
        'datasource_id' => 'entity:entity_test_mulrev_changed',
        'property_path' => $field_name,
        'type' => 'date',
      ]));
    }
    $index->save();

    $events = [
      'Early' => ['0050-01-01', '0060-06-30'],
      'Mid 18th' => ['1750-05-01', '1755-01-01'],
      'Early 20th' => ['1900-01-01', '1910-12-31'],
    ];
    foreach ($events as $name => [$start, $end]) {
      EntityTestMulRevChanged::create([
        'name' => $name,
        'type' => 'item',
        'date_start' => $start,
        'date_end' => $end,
      ])->save();
    }
    $index->indexItems();

    Role::load(RoleInterface::ANONYMOUS_ID)->grantPermission('view test entity')->save();
    View::create([
      'id' => 'range_slider_test',
      'base_table' => 'search_api_index_database_search_index',
      'display' => [
        'default' => [
          'id' => 'default',
          'display_plugin' => 'default',
          'display_options' => [
            'query' => ['type' => 'search_api_query', 'options' => ['skip_access' => TRUE]],
            'pager' => ['type' => 'none', 'options' => ['offset' => 0]],
            'exposed_form' => ['type' => 'basic', 'options' => ['submit_button' => 'Apply']],
            'style' => ['type' => 'html_list'],
            'row' => ['type' => 'fields'],
            'fields' => [
              'name' => [
                'id' => 'name',
                'table' => 'search_api_index_database_search_index',
                'field' => 'name',
                'plugin_id' => 'search_api_field',
              ],
            ],
            'filters' => [
              'range' => [
                'id' => 'range',
                'table' => 'search_api_index_database_search_index',
                'field' => 'search_api_range_filter',
                'plugin_id' => 'search_api_range_filter',
                'exposed' => TRUE,
                'expose' => ['identifier' => 'period', 'label' => 'Period', 'required' => FALSE],
                'start_field' => 'date_start',
                'end_field' => 'date_end',
                'widget' => 'slider',
                'int_range' => ['min_source' => 'index', 'max_source' => 'index'],
              ],
            ],
          ],
        ],
        'page' => [
          'id' => 'page',
          'display_plugin' => 'page',
          'display_options' => ['path' => 'range-slider'],
        ],
      ],
    ])->save();
    // The path of the new page display.
    $this->container->get('router.builder')->rebuild();
  }

  /**
   * Tests the slider and its number fields.
   */
  public function testSlider(): void {
    $assert = $this->assertSession();
    $page = $this->getSession()->getPage();
    $this->drupalGet('range-slider');
    $slider = $assert->waitForElementVisible('css', '.search-api-range-filter-slider .noUi-target');
    $this->assertNotNull($slider, 'The slider is shown.');
    $assert->pageTextContains('Early 20th');

    // Two handles, labelled after the fields, at the bounds of the results.
    $this->assertSame(['From', 'To'], $this->evaluate("[...document.querySelectorAll('.noUi-handle')].map((handle) => handle.getAttribute('aria-label'))"));
    $this->assertSame(['50', '1910'], $this->sliderValues());
    $this->assertSame(['', ''], $this->fieldValues());

    // A handle moved by the keyboard fills in its field; at the bound, the
    // field is empty again: no limit.
    $this->pressKey('lower', 'ArrowRight');
    $this->assertSame(['51', ''], $this->fieldValues());
    $this->pressKey('lower', 'Home');
    $this->assertSame(['', ''], $this->fieldValues());
    $this->pressKey('upper', 'ArrowLeft');
    $this->assertSame(['', '1909'], $this->fieldValues());

    // The fields move the handles.
    $page->fillField('period[from]', '1752');
    $page->fillField('period[to]', '1760');
    $this->evaluate("document.querySelectorAll('.search-api-range-filter-slider input').forEach((input) => input.dispatchEvent(new Event('change', {bubbles: true})))");
    $this->assertSame(['1752', '1760'], $this->sliderValues());

    // Searching uses the fields; the slider keeps the values.
    $page->pressButton('Apply');
    $this->assertNotNull($assert->waitForElementVisible('css', '.search-api-range-filter-slider .noUi-target'));
    $assert->pageTextContains('Mid 18th');
    $assert->pageTextNotContains('Early 20th');
    $this->assertSame(['1752', '1760'], $this->sliderValues());
  }

  /**
   * Tests dragging the bar between the handles.
   */
  public function testDragInterval(): void {
    $page = $this->getSession()->getPage();
    $this->drupalGet('range-slider');
    $this->assertNotNull($this->assertSession()->waitForElementVisible('css', '.search-api-range-filter-slider .noUi-target'));

    $page->fillField('period[from]', '500');
    $page->fillField('period[to]', '800');
    $this->evaluate("document.querySelectorAll('.search-api-range-filter-slider input').forEach((input) => input.dispatchEvent(new Event('change', {bubbles: true})))");

    // The interval moves as a whole, and both fields follow.
    $this->dragBar(0.1);
    [$from, $to] = array_map('intval', $this->sliderValues());
    $this->assertGreaterThan(500, $from);
    $this->assertSame(300, $to - $from);
    $this->assertSame([(string) $from, (string) $to], $this->fieldValues());

    // It stops at the bound with the same size; the field there is empty.
    $this->dragBar(1);
    $this->assertSame(['1610', '1910'], $this->sliderValues());
    $this->assertSame(['1610', ''], $this->fieldValues());

    // And back: both handles are off the bounds again.
    $this->dragBar(-0.1);
    [$from, $to] = array_map('intval', $this->sliderValues());
    $this->assertLessThan(1610, $from);
    $this->assertSame(300, $to - $from);
    $this->assertSame([(string) $from, (string) $to], $this->fieldValues());
  }

  /**
   * Drags the bar between the handles.
   *
   * @param float $fraction
   *   The distance, as a fraction of the width of the slider; negative moves
   *   to the left.
   */
  protected function dragBar(float $fraction): void {
    $this->getSession()->evaluateScript("(() => {
      const bar = document.querySelector('.noUi-connect').getBoundingClientRect();
      const width = document.querySelector('.noUi-target').getBoundingClientRect().width;
      const start = bar.left + bar.width / 2;
      const end = start + width * $fraction;
      const fire = (element, type, clientX) => element.dispatchEvent(new MouseEvent(type, {bubbles: true, cancelable: true, buttons: 1, clientX, clientY: bar.top + bar.height / 2}));
      fire(document.querySelector('.noUi-connect'), 'mousedown', start);
      fire(document.documentElement, 'mousemove', end);
      fire(document.documentElement, 'mouseup', end);
    })()");
  }

  /**
   * Presses a key on the lower or upper handle of the slider.
   */
  protected function pressKey(string $handle, string $key): void {
    $this->evaluate("document.querySelector('.noUi-handle-$handle').dispatchEvent(new KeyboardEvent('keydown', {key: '$key', bubbles: true, cancelable: true}))");
  }

  /**
   * Returns the values of the slider handles.
   */
  protected function sliderValues(): array {
    return $this->evaluate("document.querySelector('.search-api-range-filter-slider .noUi-target').noUiSlider.get().map(String)");
  }

  /**
   * Returns the values of the "from" and "to" fields.
   */
  protected function fieldValues(): array {
    return $this->evaluate("[...document.querySelectorAll('.search-api-range-filter-slider input')].map((input) => input.value)");
  }

  /**
   * Evaluates a JavaScript expression in the page.
   */
  protected function evaluate(string $expression): mixed {
    return $this->getSession()->evaluateScript("(() => $expression)()");
  }

}
