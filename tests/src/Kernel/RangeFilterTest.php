<?php

namespace Drupal\Tests\search_api_range_filter\Kernel;

use Drupal\Core\Form\FormState;
use Drupal\entity_test\Entity\EntityTestMulRevChanged;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\KernelTests\KernelTestBase;
use Drupal\search_api\Entity\Index;
use Drupal\Tests\search_api\Functional\ExampleContentTrait;
use Drupal\views\Entity\View;
use Drupal\views\ViewExecutable;
use Drupal\views\Views;

/**
 * Tests the range filter on a Search API database index.
 *
 * @group search_api_range_filter
 */
class RangeFilterTest extends KernelTestBase {

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
    'search_api_test',
    'search_api_test_db',
    'search_api_test_example_content',
    'system',
    'text',
    'user',
    'views',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installSchema('search_api', ['search_api_item']);
    $this->installEntitySchema('entity_test_mulrev_changed');
    $this->installEntitySchema('search_api_task');
    $this->installConfig(['search_api', 'search_api_test_example_content', 'search_api_test_db']);
    $this->setUpExampleStructure();

    $index = Index::load('database_search_index');
    $fields_helper = $this->container->get('search_api.fields_helper');
    foreach (['date_start' => 'datetime', 'date_end' => 'datetime', 'year_start' => 'integer', 'year_end' => 'integer'] as $field_name => $field_type) {
      FieldStorageConfig::create([
        'field_name' => $field_name,
        'entity_type' => 'entity_test_mulrev_changed',
        'type' => $field_type,
        'settings' => $field_type === 'datetime' ? ['datetime_type' => 'date'] : [],
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
        'type' => $field_type === 'datetime' ? 'date' : 'integer',
      ]));
    }
    $index->save();

    $events = [
      'early' => ['0050-01-01', '0060-06-30'],
      'end_only' => [NULL, '1650-03-01'],
      'mid_18th' => ['1750-05-01', '1755-01-01'],
      'no_end' => ['1800-06-01', NULL],
      'early_20th' => ['1900-01-01', '1910-12-31'],
      'no_date' => [NULL, NULL],
    ];
    foreach ($events as $name => [$start, $end]) {
      EntityTestMulRevChanged::create([
        'name' => $name,
        'type' => 'item',
        'date_start' => $start,
        'date_end' => $end,
        'year_start' => $start ? (int) $start : NULL,
        'year_end' => $end ? (int) $end : NULL,
      ])->save();
    }
    $index->indexItems();
  }

  /**
   * Tests year, month and date ranges on date fields.
   */
  public function testDateRanges(): void {
    $this->assertSame(['mid_18th'], $this->search('1752', '1760'));
    $this->assertSame(['early_20th', 'mid_18th', 'no_end'], $this->search('1755', ''));
    $this->assertSame(['mid_18th'], $this->search('1755-01', '1755-01'));
    $this->assertSame([], $this->search('1755-02', '1799'));
    $this->assertSame(['mid_18th'], $this->search('1754-12-31', '1755-01-01'));

    // A reversed range is swapped.
    $this->assertSame(['mid_18th'], $this->search('1760', '1752'));

    // Small years are not converted to 20xx.
    $this->assertSame(['early'], $this->search('50', '60'));

    // A missing start falls back to the end, and the other way round.
    $this->assertSame(['early', 'end_only'], $this->search('', '1700'));
    $this->assertSame(['early_20th', 'no_end'], $this->search('1800', ''));
    $this->assertSame(['early_20th'], $this->search('1801', ''));
  }

  /**
   * Tests records without an end that are still running.
   */
  public function testOpenEnded(): void {
    $this->assertSame(['early_20th', 'no_end'], $this->search('1801', '', ['empty_end' => 'open']));
    $this->assertSame(['no_end'], $this->search('2000', '', ['empty_end' => 'open']));
    // The start must still be before "to".
    $this->assertSame(['mid_18th'], $this->search('1700', '1799', ['empty_end' => 'open']));
  }

  /**
   * Tests ranges on integer fields.
   */
  public function testIntegerRanges(): void {
    $options = ['start_field' => 'year_start', 'end_field' => 'year_end'];
    $this->assertSame(['mid_18th'], $this->search('1752', '1760', $options));
    $this->assertSame(['early', 'end_only'], $this->search('', '1700', $options));
  }

  /**
   * Tests a single date field compared with the range.
   */
  public function testSingleField(): void {
    foreach (['', 'date_start'] as $end_field) {
      $options = ['end_field' => $end_field];
      $this->assertSame(['mid_18th'], $this->search('1750', '1760', $options));
      $this->assertSame([], $this->search('1751', '1760', $options));
      // Records without a start value never match.
      $this->assertSame(['early'], $this->search('', '1700', $options));
      $this->assertSame(['early_20th', 'no_end'], $this->search('1800', '', $options));
    }
  }

  /**
   * Tests the dropdown bounds taken from the results of the view.
   */
  public function testIndexBounds(): void {
    $options = [
      'widget' => 'select_range',
      'int_range' => ['min_source' => 'index', 'max_source' => 'index'],
    ];
    $this->assertSame([1910, 50], $this->getDropdownBounds($options));

    // Only the fixed (non-exposed) filters of the view count.
    $fixed_filter = [
      'id' => 'fixed_range',
      'table' => 'search_api_index_database_search_index',
      'field' => 'search_api_range_filter',
      'plugin_id' => 'search_api_range_filter',
      'start_field' => 'date_start',
      'end_field' => 'date_end',
      'value' => ['from' => '1700', 'to' => '1950'],
    ];
    $this->assertSame([1910, 1750], $this->getDropdownBounds($options, $fixed_filter));

    // Integer fields.
    $options += ['start_field' => 'year_start', 'end_field' => 'year_end'];
    $this->assertSame([1910, 50], $this->getDropdownBounds($options));

    // Views saved with "Use current year" keep working.
    $options = [
      'widget' => 'select_range',
      'int_range' => ['min' => 1700, 'use_current_year_max' => TRUE],
    ];
    $this->assertSame([(int) date('Y'), 1700], $this->getDropdownBounds($options));
  }

  /**
   * Tests that the dropdown bounds are cached until the index changes.
   */
  public function testIndexBoundsCache(): void {
    $options = [
      'widget' => 'select_range',
      'int_range' => ['min_source' => 'index', 'max_source' => 'index'],
    ];
    $this->createView($options);
    $build = function (): array {
      $view = Views::getView('range_test');
      $view->setDisplay('default');
      $view->initHandlers();
      $form = [];
      $view->filter['range']->buildExposedForm($form, new FormState());
      $values = array_keys($form['date_wrapper']['date']['from']['#options']);
      return [reset($values), end($values), $form['date_wrapper']['date']['#cache']['tags']];
    };

    [$max, $min, $tags] = $build();
    $this->assertSame([1910, 50], [$max, $min]);
    $this->assertContains('search_api_list:database_search_index', $tags);

    EntityTestMulRevChanged::create([
      'name' => 'older',
      'type' => 'item',
      'date_start' => '0010-01-01',
    ])->save();
    $this->assertSame(50, $build()[1]);

    Index::load('database_search_index')->indexItems();
    $this->assertSame(10, $build()[1]);
  }

  /**
   * Tests the number widget.
   */
  public function testNumberWidget(): void {
    $form = $this->buildExposedForm(['widget' => 'number']);
    $this->assertSame('number', $form['date_wrapper']['date']['from']['#type']);
    $this->assertSame(1, $form['date_wrapper']['date']['from']['#step']);
    $this->assertSame(['mid_18th'], $this->search('1752', '1760', ['widget' => 'number']));
  }

  /**
   * Returns the first and last option of the dropdown.
   */
  protected function getDropdownBounds(array $options, ?array $fixed_filter = NULL): array {
    $form = $this->buildExposedForm($options, $fixed_filter);
    $values = array_keys($form['date_wrapper']['date']['from']['#options']);
    return [reset($values), end($values)];
  }

  /**
   * Builds the exposed form element of the range filter.
   */
  protected function buildExposedForm(array $options, ?array $fixed_filter = NULL): array {
    $view = $this->createView($options, $fixed_filter);
    $view->initHandlers();
    $form = [];
    $view->filter['range']->buildExposedForm($form, new FormState());
    return $form;
  }

  /**
   * Tests the validation of exposed input.
   */
  public function testValidation(): void {
    $this->assertSame([], $this->getExposedErrors('1750', '1750-12-31'));
    $this->assertSame(['date][from', 'date][to'], $this->getExposedErrors('abc', '1750-13'));
    $this->assertSame(['date][to'], $this->getExposedErrors('', '1750-02-30'));
    $options = ['start_field' => 'year_start', 'end_field' => 'year_end'];
    $this->assertSame(['date][from'], $this->getExposedErrors('17.5', '', $options));
  }

  /**
   * Returns the names of the matching records, sorted.
   */
  protected function search(string $from, string $to, array $options = []): array {
    $view = $this->createView($options);
    $view->setExposedInput(['date' => ['from' => $from, 'to' => $to]]);
    $view->execute();
    $names = array_map(fn ($row) => $row->_item->getOriginalObject()->getValue()->label(), $view->result);
    sort($names);
    return $names;
  }

  /**
   * Returns the form element names with exposed input errors.
   */
  protected function getExposedErrors(string $from, string $to, array $options = []): array {
    $view = $this->createView($options);
    $view->initHandlers();
    $form = [];
    $form_state = (new FormState())->setValue('date', ['from' => $from, 'to' => $to]);
    $view->filter['range']->validateExposed($form, $form_state);
    return array_keys($form_state->getErrors());
  }

  /**
   * Creates a view with an exposed range filter.
   */
  protected function createView(array $options, ?array $fixed_filter = NULL): ViewExecutable {
    View::load('range_test')?->delete();
    View::create([
      'id' => 'range_test',
      'base_table' => 'search_api_index_database_search_index',
      'display' => [
        'default' => [
          'id' => 'default',
          'display_plugin' => 'default',
          'display_options' => [
            'query' => ['type' => 'search_api_query', 'options' => ['skip_access' => TRUE]],
            'pager' => ['type' => 'none', 'options' => ['offset' => 0]],
            'filters' => [
              'range' => $options + [
                'id' => 'range',
                'table' => 'search_api_index_database_search_index',
                'field' => 'search_api_range_filter',
                'plugin_id' => 'search_api_range_filter',
                'exposed' => TRUE,
                'expose' => ['identifier' => 'date', 'label' => 'Date', 'required' => FALSE],
                'start_field' => 'date_start',
                'end_field' => 'date_end',
              ],
            ] + ($fixed_filter ? [$fixed_filter['id'] => $fixed_filter] : []),
          ],
        ],
      ],
    ])->save();

    $view = Views::getView('range_test');
    $view->setDisplay('default');
    return $view;
  }

}
