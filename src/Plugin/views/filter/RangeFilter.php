<?php

namespace Drupal\search_api_range_filter\Plugin\views\filter;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Cache\Cache;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Language\LanguageInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Session\AnonymousUserSession;
use Drupal\search_api\Plugin\views\query\SearchApiQuery;
use Drupal\search_api\Plugin\views\SearchApiHandlerTrait;
use Drupal\search_api\Query\QueryInterface;
use Drupal\views\Plugin\views\filter\FilterPluginBase;
use Drupal\views\ViewExecutableFactory;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Filters on the overlap of a [start, end] interval with a [from, to] range.
 *
 * A record matches when its interval, stored in two index fields, overlaps the
 * range entered by the user:
 *
 * @code
 *   COALESCE(end, start) >= from  AND  COALESCE(start, end) <= to
 * @endcode
 *
 * When records without an end are "still running", they match every "from"
 * value, as long as they have a start.
 *
 * Without an end field (or with the same field twice), the filter matches
 * records whose single value lies within the range.
 *
 * Date values are passed to the backend as UTC timestamps in numeric strings:
 * the Database backend casts them to integers, and Elasticsearch parses them
 * as epoch seconds (a JSON number would be parsed differently).
 *
 * @ingroup views_filter_handlers
 *
 * @ViewsFilter("search_api_range_filter")
 */
class RangeFilter extends FilterPluginBase implements ContainerFactoryPluginInterface {

  use SearchApiHandlerTrait;

  /**
   * {@inheritdoc}
   */
  // phpcs:ignore Drupal.NamingConventions.ValidVariableName.LowerCamelName
  public $no_operator = TRUE;

  /**
   * Index field types that support range comparisons.
   */
  protected const RANGE_TYPES = ['date', 'integer', 'decimal'];

  /**
   * Whether a lowest/highest value lookup is running, to prevent recursion.
   */
  protected static bool $lookupRunning = FALSE;

  /**
   * Constructs a RangeFilter.
   *
   * @param array $configuration
   *   A configuration array containing information about the plugin instance.
   * @param string $plugin_id
   *   The plugin ID for the plugin instance.
   * @param mixed $plugin_definition
   *   The plugin implementation definition.
   * @param \Drupal\Component\Datetime\TimeInterface $time
   *   The time service.
   * @param \Drupal\Core\Cache\CacheBackendInterface $cache
   *   The cache backend for the lowest and highest values.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   * @param \Drupal\views\ViewExecutableFactory $executableFactory
   *   The view executable factory.
   * @param \Drupal\Core\Language\LanguageManagerInterface $languageManager
   *   The language manager.
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    protected TimeInterface $time,
    protected CacheBackendInterface $cache,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected ViewExecutableFactory $executableFactory,
    protected LanguageManagerInterface $languageManager,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('datetime.time'),
      $container->get('cache.data'),
      $container->get('entity_type.manager'),
      $container->get('views.executable'),
      $container->get('language_manager'),
    );
  }

  /**
   * {@inheritdoc}
   */
  protected function defineOptions() {
    $options = parent::defineOptions();

    $options['value'] = ['default' => ['from' => '', 'to' => '']];
    $options['start_field'] = ['default' => ''];
    $options['end_field'] = ['default' => ''];
    $options['empty_end'] = ['default' => 'start'];
    $options['widget'] = ['default' => 'textfield'];
    $options['int_range'] = ['default' => []];
    $options['from_label'] = ['default' => 'From'];
    $options['to_label'] = ['default' => 'To'];

    return $options;
  }

  /**
   * {@inheritdoc}
   */
  protected function canBuildGroup() {
    return FALSE;
  }

  /**
   * {@inheritdoc}
   */
  public function buildOptionsForm(&$form, FormStateInterface $form_state) {
    parent::buildOptionsForm($form, $form_state);

    $field_options = $this->getRangeCapableFields();
    if (empty($field_options)) {
      $form['range_config_message'] = [
        '#type' => 'markup',
        '#markup' => '<p class="messages messages--warning">' . $this->t('No range-capable fields (date, integer, decimal) found in this index. Make sure the view is backed by a Search API index and that date or numeric fields are indexed.') . '</p>',
      ];
      return;
    }

    $form['range_config'] = [
      '#type' => 'details',
      '#title' => $this->t('Range filter configuration'),
      '#open' => TRUE,
      '#weight' => -10,
    ];

    $form['range_config']['start_field'] = [
      '#type' => 'select',
      '#title' => $this->t('Start field'),
      '#options' => $field_options,
      '#default_value' => $this->options['start_field'],
      '#empty_option' => $this->t('- Select -'),
      '#description' => $this->t('Index field that holds the beginning of the range (e.g. <em>date_start</em>).'),
      '#required' => TRUE,
    ];

    $form['range_config']['end_field'] = [
      '#type' => 'select',
      '#title' => $this->t('End field'),
      '#options' => $field_options,
      '#default_value' => $this->options['end_field'],
      '#empty_option' => $this->t('- Select -'),
      '#description' => $this->t('Index field that holds the end of the range (e.g. <em>date_end</em>). Must have the same type as the start field. Leave empty to filter on a single date or number.'),
    ];

    $form['range_config']['empty_end'] = [
      '#type' => 'radios',
      '#title' => $this->t('Records without an end value'),
      '#options' => [
        'start' => $this->t('End at their start value'),
        'open' => $this->t('Are still running (match every "from" value)'),
      ],
      '#default_value' => $this->options['empty_end'],
      '#states' => [
        'invisible' => [':input[name="options[range_config][end_field]"]' => ['value' => '']],
      ],
    ];

    $form['range_config']['from_label'] = [
      '#type' => 'textfield',
      '#title' => $this->t('"From" label'),
      '#default_value' => $this->options['from_label'],
      '#description' => $this->t('Label shown next to the "from" input in the exposed filter.'),
      '#size' => 20,
    ];

    $form['range_config']['to_label'] = [
      '#type' => 'textfield',
      '#title' => $this->t('"To" label'),
      '#default_value' => $this->options['to_label'],
      '#description' => $this->t('Label shown next to the "to" input in the exposed filter.'),
      '#size' => 20,
    ];

    $form['range_config']['widget'] = [
      '#type' => 'radios',
      '#title' => $this->t('Widget type'),
      '#options' => [
        'textfield' => $this->t('Text field (on date fields: YYYY, YYYY-MM or YYYY-MM-DD)'),
        'number' => $this->t('Number field (on date fields: a year)'),
        'select_range' => $this->t('Dropdown (consecutive integer range)'),
      ],
      '#default_value' => $this->options['widget'],
      '#description' => $this->t('Input widget shown to end users. Use <em>Dropdown</em> or <em>Number field</em> for year ranges.'),
    ];

    $this->buildIntRangeSubForm($form['range_config'], $this->options['int_range']);
  }

  /**
   * Adds the dropdown range settings to the options form.
   *
   * @param array $parent
   *   Form container to attach the int_range group to.
   * @param array $saved
   *   Previously saved int_range values.
   */
  protected function buildIntRangeSubForm(array &$parent, array $saved): void {
    $widget = ':input[name="options[range_config][widget]"]';
    $parent['int_range'] = [
      '#type' => 'container',
      '#states' => ['visible' => [$widget => ['value' => 'select_range']]],
    ];

    $labels = [
      'min' => [
        'title' => $this->t('Minimum value'),
        'index' => $this->t('Lowest value in the results'),
      ],
      'max' => [
        'title' => $this->t('Maximum value'),
        'index' => $this->t('Highest value in the results'),
      ],
    ];
    foreach ($labels as $key => $label) {
      $source = ':input[name="options[range_config][int_range][' . $key . '_source]"]';
      $fixed = [$widget => ['value' => 'select_range'], $source => ['value' => 'fixed']];

      $parent['int_range'][$key . '_source'] = [
        '#type' => 'radios',
        '#title' => $label['title'],
        '#options' => [
          'fixed' => $this->t('Fixed value'),
          'current_year' => $this->t('Current year'),
          'index' => $label['index'],
        ],
        '#default_value' => $this->getBoundSource($saved, $key),
        '#description' => $key === 'min'
          ? $this->t('"Lowest value in the results" uses the start values of the items this view shows without its exposed filters. On date fields, this is the year.')
          : $this->t('"Highest value in the results" uses the end values of the items this view shows without its exposed filters. On date fields, this is the year.'),
      ];
      $parent['int_range'][$key] = [
        '#type' => 'number',
        '#title' => $label['title'],
        '#title_display' => 'invisible',
        '#default_value' => $saved[$key] ?? ($key === 'min' ? 1 : $this->getCurrentYear()),
        '#size' => 10,
        '#states' => ['visible' => $fixed, 'required' => $fixed],
      ];
    }
  }

  /**
   * {@inheritdoc}
   */
  public function validateOptionsForm(&$form, FormStateInterface $form_state) {
    parent::validateOptionsForm($form, $form_state);

    $config = $form_state->getValue(['options', 'range_config']) ?? [];
    $start = $config['start_field'] ?? '';
    $end = $config['end_field'] ?? '';

    if ($start && $end && $this->getFieldType($start) !== $this->getFieldType($end)) {
      $form_state->setError($form['range_config']['end_field'], $this->t('The start field and end field must have the same type.'));
    }

    if (($config['widget'] ?? '') === 'select_range') {
      $int_range = $config['int_range'] ?? [];
      foreach (['min' => $this->t('minimum'), 'max' => $this->t('maximum')] as $key => $label) {
        if ($this->getBoundSource($int_range, $key) === 'fixed' && ($int_range[$key] ?? '') === '') {
          $form_state->setError($form['range_config']['int_range'][$key], $this->t('A @label value is required for the dropdown.', ['@label' => $label]));
        }
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitOptionsForm(&$form, FormStateInterface $form_state) {
    parent::submitOptionsForm($form, $form_state);

    $config = $form_state->getValue(['options', 'range_config']) ?? [];
    foreach (['start_field', 'end_field', 'empty_end', 'widget', 'int_range', 'from_label', 'to_label'] as $key) {
      if (array_key_exists($key, $config)) {
        $this->options[$key] = $config[$key];
      }
    }
    // Replaced by min_source and max_source.
    unset($this->options['int_range']['use_current_year_min'], $this->options['int_range']['use_current_year_max']);
  }

  /**
   * {@inheritdoc}
   *
   * Skips the filter when both values are empty.
   */
  public function acceptExposedInput($input) {
    if (empty($this->options['exposed'])) {
      return TRUE;
    }

    $accepted = parent::acceptExposedInput($input);
    if ($accepted && empty($this->options['expose']['required'])) {
      $value = is_array($this->value) ? $this->value : [];
      if ($this->cleanInput($value['from'] ?? '') === '' && $this->cleanInput($value['to'] ?? '') === '') {
        return FALSE;
      }
    }

    return $accepted;
  }

  /**
   * {@inheritdoc}
   */
  public function validateExposed(&$form, FormStateInterface $form_state) {
    if (empty($this->options['exposed'])) {
      return;
    }

    $identifier = $this->options['expose']['identifier'];
    $values = $form_state->getValue($identifier);
    if (!is_array($values)) {
      return;
    }

    $type = $this->getFieldType($this->options['start_field']);
    foreach (['from' => FALSE, 'to' => TRUE] as $key => $is_end) {
      $value = $this->cleanInput($values[$key] ?? '');
      if ($value !== '' && $this->convertValue($value, $type, $is_end) === NULL) {
        $message = $type === 'date'
          ? $this->t('Enter a year (YYYY), a month (YYYY-MM) or a date (YYYY-MM-DD).')
          : $this->t('Enter a number.');
        $form_state->setErrorByName($identifier . '][' . $key, $message);
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  protected function valueForm(&$form, FormStateInterface $form_state) {
    // Submitted values arrive as ['from' => ..., 'to' => ...].
    $form['value']['#tree'] = TRUE;

    $values = is_array($this->value) ? $this->value : [];
    $from_label = $this->options['from_label'] ?: $this->t('From');
    $to_label = $this->options['to_label'] ?: $this->t('To');

    if ($this->options['widget'] === 'select_range') {
      $int_range = $this->options['int_range'] ?? [];
      $options = $this->buildIntRangeOptions($int_range);
      foreach (['from' => $from_label, 'to' => $to_label] as $key => $label) {
        $form['value'][$key] = [
          '#type' => 'select',
          '#title' => $label,
          '#options' => $options,
          '#default_value' => $values[$key] ?? '',
          '#empty_option' => $this->t('- Any -'),
        ];
      }
      $form['value']['#cache'] = $this->getIntRangeCacheability($int_range);
      return;
    }

    $type = $this->getFieldType($this->options['start_field']);
    foreach (['from' => $from_label, 'to' => $to_label] as $key => $label) {
      $form['value'][$key] = [
        '#type' => 'textfield',
        '#title' => $label,
        '#default_value' => $values[$key] ?? '',
        '#size' => 20,
        '#placeholder' => $type === 'date' ? 'YYYY-MM-DD' : '',
      ];
      if ($this->options['widget'] === 'number') {
        $form['value'][$key]['#type'] = 'number';
        $form['value'][$key]['#step'] = $type === 'decimal' ? 'any' : 1;
        $form['value'][$key]['#placeholder'] = $type === 'date' ? 'YYYY' : '';
      }
    }
  }

  /**
   * {@inheritdoc}
   *
   * Builds the overlap conditions:
   *
   * @code
   *   AND(
   *     OR(end >= from, AND(end IS NULL, start >= from)),
   *     OR(start <= to, AND(start IS NULL, end <= to))
   *   )
   * @endcode
   *
   * When records without an end are "still running", the first part becomes
   * OR(end >= from, AND(end IS NULL, start IS NOT NULL)).
   *
   * Without an end field, this is simply: start >= from AND start <= to.
   */
  public function query() {
    $query = $this->getQuery();
    $start_field = $this->options['start_field'];
    $end_field = $this->options['end_field'] ?: $start_field;
    if (!$query instanceof SearchApiQuery || !$start_field) {
      return;
    }

    [$from, $to] = $this->getBoundaries();
    if ($from === NULL && $to === NULL) {
      return;
    }

    $overlap = $query->createConditionGroup('AND');
    if (!$overlap) {
      return;
    }

    if ($end_field === $start_field) {
      if ($from !== NULL) {
        $overlap->addCondition($start_field, $from, '>=');
      }
      if ($to !== NULL) {
        $overlap->addCondition($start_field, $to, '<=');
      }
      $query->addConditionGroup($overlap, $this->options['group']);
      return;
    }

    if ($from !== NULL) {
      $from_or = $query->createConditionGroup('OR');
      $from_or->addCondition($end_field, $from, '>=');
      $end_missing = $query->createConditionGroup('AND');
      $end_missing->addCondition($end_field, NULL);
      if ($this->options['empty_end'] === 'open') {
        $end_missing->addCondition($start_field, NULL, '<>');
      }
      else {
        $end_missing->addCondition($start_field, $from, '>=');
      }
      $from_or->addConditionGroup($end_missing);
      $overlap->addConditionGroup($from_or);
    }

    if ($to !== NULL) {
      $to_or = $query->createConditionGroup('OR');
      $to_or->addCondition($start_field, $to, '<=');
      $start_missing = $query->createConditionGroup('AND');
      $start_missing->addCondition($start_field, NULL);
      $start_missing->addCondition($end_field, $to, '<=');
      $to_or->addConditionGroup($start_missing);
      $overlap->addConditionGroup($to_or);
    }

    $query->addConditionGroup($overlap, $this->options['group']);
  }

  /**
   * {@inheritdoc}
   */
  public function adminSummary() {
    $start = $this->options['start_field'];
    $end = $this->options['end_field'];
    if (!$start) {
      return $this->t('Not configured');
    }
    if (!$end || $end === $start) {
      return $start;
    }
    return $this->t('@start → @end', ['@start' => $start, '@end' => $end]);
  }

  /**
   * Returns the "from" and "to" values in the format of the index fields.
   *
   * Reversed ranges are swapped. Invalid values are ignored.
   *
   * @return array{0: string|null, 1: string|null}
   *   The lower and upper boundary, or NULL when not set.
   */
  protected function getBoundaries(): array {
    $values = is_array($this->value) ? $this->value : [];
    $from = $this->cleanInput($values['from'] ?? '');
    $to = $this->cleanInput($values['to'] ?? '');
    $type = $this->getFieldType($this->options['start_field']);

    $lower = $from === '' ? NULL : $this->convertValue($from, $type, FALSE);
    $upper = $to === '' ? NULL : $this->convertValue($to, $type, TRUE);

    if ($lower !== NULL && $upper !== NULL && (float) $lower > (float) $upper) {
      $lower = $this->convertValue($to, $type, FALSE);
      $upper = $this->convertValue($from, $type, TRUE);
    }

    return [$lower, $upper];
  }

  /**
   * Converts a user value to the format of the index field.
   *
   * @param string $value
   *   The cleaned user input.
   * @param string|null $type
   *   The Search API type of the index field.
   * @param bool $is_end
   *   TRUE for the upper boundary: a year or month then means its last second.
   *
   * @return string|null
   *   The value as a numeric string, or NULL when it is invalid.
   */
  protected function convertValue(string $value, ?string $type, bool $is_end): ?string {
    return match ($type) {
      'date' => $this->convertDate($value, $is_end),
      'integer' => preg_match('/^-?\d+$/', $value) ? $value : NULL,
      default => is_numeric($value) ? $value : NULL,
    };
  }

  /**
   * Converts a year, month or date to a UTC timestamp.
   *
   * Supports historical and negative years: "50" is the year 50, not 2050.
   *
   * @param string $value
   *   A year (YYYY), a month (YYYY-MM) or a date (YYYY-MM-DD).
   * @param bool $is_end
   *   TRUE to return the last second of the period, FALSE for the first.
   *
   * @return string|null
   *   The timestamp as a numeric string, or NULL when the value is invalid.
   */
  protected function convertDate(string $value, bool $is_end): ?string {
    if (!preg_match('/^(-?\d{1,4})(?:-(\d{1,2})(?:-(\d{1,2}))?)?$/', $value, $matches)) {
      return NULL;
    }
    $year = (int) $matches[1];
    $month = isset($matches[2]) ? (int) $matches[2] : NULL;
    $day = isset($matches[3]) ? (int) $matches[3] : NULL;
    if ($month !== NULL && ($month < 1 || $month > 12)) {
      return NULL;
    }

    $date = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))
      ->setDate($year, $month ?? ($is_end ? 12 : 1), 1)
      ->setTime(0, 0);
    if ($day !== NULL) {
      if ($day < 1 || $day > (int) $date->format('t')) {
        return NULL;
      }
      $date = $date->setDate($year, $month, $day);
    }
    elseif ($is_end) {
      $date = $date->modify('last day of this month');
    }
    if ($is_end) {
      $date = $date->setTime(23, 59, 59);
    }

    return (string) $date->getTimestamp();
  }

  /**
   * Strips HTML, trims whitespace, and limits a value to 255 characters.
   */
  protected function cleanInput(mixed $value): string {
    return mb_substr(trim(strip_tags((string) $value)), 0, 255);
  }

  /**
   * Returns the Search API type of an index field.
   */
  protected function getFieldType(string $field_id): ?string {
    return $field_id !== '' ? $this->getIndex()?->getField($field_id)?->getType() : NULL;
  }

  /**
   * Returns the range-capable fields of the index.
   *
   * @return array
   *   Labels keyed by field ID, as "Label [field_id]".
   */
  protected function getRangeCapableFields(): array {
    $fields = [];
    foreach ($this->getIndex()?->getFields() ?? [] as $field_id => $field) {
      if (in_array($field->getType(), static::RANGE_TYPES, TRUE)) {
        $fields[$field_id] = $field->getLabel() . ' [' . $field_id . ']';
      }
    }
    return $fields;
  }

  /**
   * Builds the dropdown options, newest first.
   *
   * @param array $int_range
   *   The int_range options.
   *
   * @return array
   *   Integers keyed by themselves, e.g. [2024 => 2024, 2023 => 2023, …].
   */
  protected function buildIntRangeOptions(array $int_range): array {
    $bounds = [];
    foreach (['min', 'max'] as $key) {
      $bounds[$key] = match ($this->getBoundSource($int_range, $key)) {
        'current_year' => $this->getCurrentYear(),
        'index' => $this->getIndexBound($key)['value'],
        default => NULL,
      };
      // Fall back to the fixed value, e.g. when there are no results.
      if ($bounds[$key] === NULL && isset($int_range[$key]) && $int_range[$key] !== '') {
        $bounds[$key] = (int) $int_range[$key];
      }
      if ($bounds[$key] === NULL) {
        return [];
      }
    }

    $values = range(max($bounds), min($bounds));
    return array_combine($values, $values);
  }

  /**
   * Returns where a dropdown bound comes from.
   *
   * @param array $int_range
   *   The int_range options.
   * @param string $key
   *   Either 'min' or 'max'.
   *
   * @return string
   *   'fixed', 'current_year' or 'index'.
   */
  protected function getBoundSource(array $int_range, string $key): string {
    // Views saved before min_source and max_source existed use a boolean.
    return $int_range[$key . '_source']
      ?? (!empty($int_range['use_current_year_' . $key]) ? 'current_year' : 'fixed');
  }

  /**
   * Returns the cacheability of the dropdown.
   *
   * @param array $int_range
   *   The int_range options.
   *
   * @return array
   *   A #cache array.
   */
  protected function getIntRangeCacheability(array $int_range): array {
    $cache = ['tags' => [], 'max-age' => Cache::PERMANENT];
    foreach (['min', 'max'] as $key) {
      $source = $this->getBoundSource($int_range, $key);
      if ($source === 'current_year') {
        // The dropdown changes on 1 January.
        $cache['max-age'] = $this->getSecondsUntilNextYear();
      }
      elseif ($source === 'index') {
        $cache['tags'] = Cache::mergeTags($cache['tags'], $this->getIndexBound($key)['tags']);
      }
    }
    return $cache;
  }

  /**
   * Returns the lowest or highest value of the items this view shows.
   *
   * The view is run without its exposed filters and sorts, so the result only
   * depends on its fixed filters and arguments. It is cached until the index
   * or the view changes.
   *
   * @param string $key
   *   Either 'min' for the lowest value or 'max' for the highest value.
   *
   * @return array{value: int|null, tags: string[]}
   *   The value (a year on date fields), or NULL when there are no values,
   *   and the cache tags.
   */
  protected function getIndexBound(string $key): array {
    $language = $this->languageManager->getCurrentLanguage(LanguageInterface::TYPE_CONTENT)->getId();
    $cid = implode(':', [
      'search_api_range_filter',
      $this->view->id(),
      $this->view->current_display,
      $this->options['id'],
      $key,
      $language,
      hash('sha256', serialize($this->view->args)),
    ]);
    if ($cached = $this->cache->get($cid)) {
      return $cached->data;
    }
    if (static::$lookupRunning) {
      return ['value' => NULL, 'tags' => []];
    }

    static::$lookupRunning = TRUE;
    try {
      $bound = $this->lookUpIndexBound($key);
    }
    finally {
      static::$lookupRunning = FALSE;
    }
    $this->cache->set($cid, $bound, Cache::PERMANENT, $bound['tags']);
    return $bound;
  }

  /**
   * Looks up the lowest or highest value of the items this view shows.
   *
   * The lowest value is the lowest start or end value, so records without a
   * start value count with their end value; the same goes for the highest.
   *
   * @param string $key
   *   Either 'min' or 'max'.
   *
   * @return array{value: int|null, tags: string[]}
   *   The value and the cache tags.
   */
  protected function lookUpIndexBound(string $key): array {
    $type = $this->getFieldType($this->options['start_field']);
    $fields = array_unique(array_filter([$this->options['start_field'], $this->options['end_field']]));
    $tags = $this->view->storage->getCacheTags();
    $values = [];

    foreach ($fields as $field) {
      $query = $this->buildLookupQuery();
      if (!$query) {
        break;
      }
      $tags = Cache::mergeTags($tags, $query->getCacheTags());
      $tags = Cache::mergeTags($tags, ['search_api_list:' . $query->getIndex()->id()]);
      $query->addCondition($field, NULL, '<>');
      $sorts = &$query->getSorts();
      $sorts = [];
      $query->sort($field, $key === 'min' ? QueryInterface::SORT_ASC : QueryInterface::SORT_DESC);
      $query->range(0, 1);
      foreach ($query->execute()->getResultItems() as $item) {
        foreach ($item->getField($field)?->getValues() ?? [] as $value) {
          if (is_numeric($value)) {
            $values[] = $type === 'date' ? (int) gmdate('Y', (int) $value) : $value;
          }
        }
      }
    }

    if (!$values) {
      return ['value' => NULL, 'tags' => $tags];
    }
    $value = $key === 'min' ? floor(min($values)) : ceil(max($values));
    return ['value' => (int) $value, 'tags' => $tags];
  }

  /**
   * Builds the search query of this view without its exposed filters.
   *
   * @return \Drupal\search_api\Query\QueryInterface|null
   *   The search query, or NULL when the view cannot be built.
   */
  protected function buildLookupQuery(): ?QueryInterface {
    $storage = $this->entityTypeManager->getStorage('view')->loadUnchanged($this->view->id());
    if (!$storage) {
      return NULL;
    }
    $view = $this->executableFactory->get($storage);
    if (!$view->setDisplay($this->view->current_display)) {
      return NULL;
    }
    $view->setArguments($this->view->args);
    $view->setExposedInput([]);
    $view->initHandlers();
    foreach ($view->filter as $id => $filter) {
      if ($filter->isExposed() || $id === $this->options['id']) {
        unset($view->filter[$id]);
      }
    }
    $view->sort = [];
    $view->build();

    if (!empty($view->build_info['fail']) || !$view->query instanceof SearchApiQuery || $view->query->shouldAbort()) {
      return NULL;
    }
    $query = $view->query->getSearchApiQuery();
    // Do not let access checks for the current user end up in the cache.
    $query->setOption('search_api_access_account', new AnonymousUserSession());
    return $query;
  }

  /**
   * Returns the current year in the site or user timezone.
   */
  protected function getCurrentYear(): int {
    return (int) date('Y', $this->time->getRequestTime());
  }

  /**
   * Returns the number of seconds until 1 January of next year.
   */
  protected function getSecondsUntilNextYear(): int {
    $next_year = new \DateTimeImmutable(($this->getCurrentYear() + 1) . '-01-01 00:00:00');
    return max(60, $next_year->getTimestamp() - $this->time->getRequestTime());
  }

}
