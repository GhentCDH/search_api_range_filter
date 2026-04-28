<?php

namespace Drupal\search_api_range_filter\Plugin\views\filter;

use Drupal\Core\Form\FormStateInterface;
use Drupal\search_api\Entity\Index;
use Drupal\search_api\Plugin\views\filter\SearchApiFilterTrait;
use Drupal\search_api\Plugin\views\query\SearchApiQuery;
use Drupal\views\Plugin\views\filter\FilterPluginBase;

/**
 * Search API Views filter that performs range-overlap filtering across two
 * index fields (e.g. a start date and an end date).
 *
 * A record matches when its [start_field, end_field] interval overlaps the
 * [from, to] values supplied by the end user.  Either field may be empty on
 * a record; the COALESCE fallback ensures those records still match when the
 * non-empty field satisfies the condition.
 *
 * Overlap formula:
 *   COALESCE(end, start) >= from   (end-field absent → use start as fallback)
 *   AND
 *   COALESCE(start, end) <= to     (start-field absent → use end as fallback)
 *
 * @ViewsFilter("search_api_range_filter")
 */
class RangeFilter extends FilterPluginBase {

  use SearchApiFilterTrait;

  // -------------------------------------------------------------------------
  // Options
  // -------------------------------------------------------------------------

  /**
   * {@inheritdoc}
   */
  public function defineOptions(): array {
    $options = parent::defineOptions();

    // Default value stored as array so from/to sub-keys always exist.
    $options['value'] = ['default' => ['from' => '', 'to' => '']];

    // Range configuration options.
    $options['start_field'] = ['default' => ''];
    $options['end_field'] = ['default' => ''];
    $options['widget'] = ['default' => 'textfield'];
    $options['int_range'] = ['default' => []];
    $options['from_label'] = ['default' => 'From'];
    $options['to_label'] = ['default' => 'To'];

    return $options;
  }

  // -------------------------------------------------------------------------
  // Admin configuration form
  // -------------------------------------------------------------------------

  /**
   * {@inheritdoc}
   */
  public function buildOptionsForm(&$form, FormStateInterface $form_state): void {
    parent::buildOptionsForm($form, $form_state);

    // The parent adds a 'value' element that we don't need here.
    if (isset($form['value'])) {
      $form['value']['#access'] = FALSE;
    }

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
      '#description' => $this->t('Index field that holds the end of the range (e.g. <em>date_end</em>). Records where this field is empty will still match if the start field satisfies the condition.'),
      '#required' => TRUE,
    ];

    $form['range_config']['from_label'] = [
      '#type' => 'textfield',
      '#title' => $this->t('"From" label'),
      '#default_value' => $this->options['from_label'] ?: $this->t('From'),
      '#description' => $this->t('Label shown next to the "from" input in the exposed filter.'),
      '#size' => 20,
    ];

    $form['range_config']['to_label'] = [
      '#type' => 'textfield',
      '#title' => $this->t('"To" label'),
      '#default_value' => $this->options['to_label'] ?: $this->t('To'),
      '#description' => $this->t('Label shown next to the "to" input in the exposed filter.'),
      '#size' => 20,
    ];

    $form['range_config']['widget'] = [
      '#type' => 'radios',
      '#title' => $this->t('Widget type'),
      '#options' => [
        'textfield' => $this->t('Text field'),
        'select_range' => $this->t('Dropdown (consecutive integer range)'),
      ],
      '#default_value' => $this->options['widget'],
      '#description' => $this->t('Input widget shown to end users. Use <em>Dropdown</em> for numeric year ranges.'),
    ];

    $this->buildIntRangeSubForm($form['range_config'], $this->options['int_range']);
  }


  /**
   * Appends int_range sub-form elements to a parent form container.
   *
   * Mirrors the same helper in NestedFieldViewsFilterConfigurator so that
   * the behaviour (current-year checkbox, visibility states) is identical.
   *
   * @param array &$parent
   *   Form container to attach the int_range group to.
   * @param array $saved
   *   Previously saved int_range values.
   */
  protected function buildIntRangeSubForm(array &$parent, array $saved): void {
    // Input name of the widget radio — used in #states conditions.
    $widget_name = 'options[range_config][widget]';
    $cur_year_min = 'options[range_config][int_range][use_current_year_min]';
    $cur_year_max = 'options[range_config][int_range][use_current_year_max]';

    $range_visible = [
      'visible' => [':input[name="' . $widget_name . '"]' => ['value' => 'select_range']],
    ];

    $min_state = [
      'visible' => [
        ':input[name="' . $widget_name . '"]' => ['value' => 'select_range'],
        ':input[name="' . $cur_year_min . '"]' => ['checked' => FALSE],
      ],
      'required' => [
        ':input[name="' . $widget_name . '"]' => ['value' => 'select_range'],
        ':input[name="' . $cur_year_min . '"]' => ['checked' => FALSE],
      ],
    ];

    $max_state = [
      'visible' => [
        ':input[name="' . $widget_name . '"]' => ['value' => 'select_range'],
        ':input[name="' . $cur_year_max . '"]' => ['checked' => FALSE],
      ],
      'required' => [
        ':input[name="' . $widget_name . '"]' => ['value' => 'select_range'],
        ':input[name="' . $cur_year_max . '"]' => ['checked' => FALSE],
      ],
    ];

    $parent['int_range'] = ['#type' => 'container'];

    $parent['int_range']['min'] = [
      '#type' => 'number',
      '#title' => $this->t('Minimum value'),
      '#default_value' => $saved['min'] ?? 1,
      '#description' => $this->t('Starting value for the dropdown.'),
      '#size' => 10,
      '#states' => $min_state,
    ];

    $parent['int_range']['use_current_year_min'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Use current year as minimum'),
      '#default_value' => $saved['use_current_year_min'] ?? FALSE,
      '#description' => $this->t('Overrides the minimum value above with the current year.'),
      '#states' => $range_visible,
    ];

    $parent['int_range']['max'] = [
      '#type' => 'number',
      '#title' => $this->t('Maximum value'),
      '#default_value' => $saved['max'] ?? (int) date('Y'),
      '#description' => $this->t('Ending value for the dropdown.'),
      '#size' => 10,
      '#states' => $max_state,
    ];

    $parent['int_range']['use_current_year_max'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Use current year as maximum'),
      '#default_value' => $saved['use_current_year_max'] ?? TRUE,
      '#description' => $this->t('Overrides the maximum value above with the current year.'),
      '#states' => $range_visible,
    ];
  }


  /**
   * {@inheritdoc}
   */
  public function validateOptionsForm(&$form, FormStateInterface $form_state): void {
    parent::validateOptionsForm($form, $form_state);

    $config = $form_state->getValue(['options', 'range_config']) ?? [];
    $start = $config['start_field'] ?? '';
    $end = $config['end_field'] ?? '';

    if ($start && $end && $start === $end) {
      $form_state->setError(
        $form['range_config']['end_field'],
        $this->t('The start field and end field must be different.')
      );
    }

    if (($config['widget'] ?? '') === 'select_range') {
      $ir = $config['int_range'] ?? [];
      if (empty($ir['use_current_year_min']) && ($ir['min'] === '' || $ir['min'] === NULL)) {
        $form_state->setError(
          $form['range_config']['int_range']['min'],
          $this->t('A minimum value is required for the integer range dropdown.')
        );
      }
      if (empty($ir['use_current_year_max']) && ($ir['max'] === '' || $ir['max'] === NULL)) {
        $form_state->setError(
          $form['range_config']['int_range']['max'],
          $this->t('A maximum value is required for the integer range dropdown.')
        );
      }
    }
  }


  /**
   * {@inheritdoc}
   */
  public function submitOptionsForm(&$form, FormStateInterface $form_state): void {
    parent::submitOptionsForm($form, $form_state);

    $config = $form_state->getValue(['options', 'range_config']) ?? [];

    foreach (['start_field', 'end_field', 'widget', 'int_range', 'from_label', 'to_label'] as $key) {
      if (array_key_exists($key, $config)) {
        $this->options[$key] = $config[$key];
      }
    }
  }


  // -------------------------------------------------------------------------
  // Exposed input acceptance
  // -------------------------------------------------------------------------

  /**
   * {@inheritdoc}
   *
   * Skip the filter entirely when both from and to are empty.
   */
  public function acceptExposedInput($input): bool {
    if (empty($this->options['exposed'])) {
      return TRUE;
    }

    $rc = parent::acceptExposedInput($input);

    if ($rc && empty($this->options['expose']['required'])) {
      $value = $this->value;
      if (is_array($value) && ($value['from'] ?? '') === '' && ($value['to'] ?? '') === '') {
        return FALSE;
      }
    }

    return $rc;
  }


  // -------------------------------------------------------------------------
  // Exposed filter widget
  // -------------------------------------------------------------------------

  /**
   * {@inheritdoc}
   */
  protected function valueForm(&$form, FormStateInterface $form_state): void {
    // Required so submitted values arrive as ['from' => ..., 'to' => ...].
    $form['value']['#tree'] = TRUE;

    $widget = $this->options['widget'] ?? 'textfield';
    $from_label = $this->options['from_label'] ?: $this->t('From');
    $to_label = $this->options['to_label'] ?: $this->t('To');

    // Resolve current values whether coming from a submission or defaults.
    $values = is_array($this->value) ? $this->value : [];
    $from_val = $values['from'] ?? '';
    $to_val = $values['to'] ?? '';

    if ($widget === 'select_range') {
      $options = $this->buildIntRangeOptions($this->options['int_range'] ?? []);

      $form['value']['from'] = [
        '#type' => 'select',
        '#title' => $from_label,
        '#options' => $options,
        '#default_value' => $from_val,
        '#empty_option' => $this->t('- Any -'),
      ];

      $form['value']['to'] = [
        '#type' => 'select',
        '#title' => $to_label,
        '#options' => $options,
        '#default_value' => $to_val,
        '#empty_option' => $this->t('- Any -'),
      ];
    }
    else {
      $form['value']['from'] = [
        '#type' => 'textfield',
        '#title' => $from_label,
        '#default_value' => $from_val,
        '#size' => 20,
      ];

      $form['value']['to'] = [
        '#type' => 'textfield',
        '#title' => $to_label,
        '#default_value' => $to_val,
        '#size' => 20,
      ];
    }
  }


  // -------------------------------------------------------------------------
  // Query building
  // -------------------------------------------------------------------------

  /**
   * {@inheritdoc}
   *
   * Builds range-overlap conditions on the two configured index fields.
   *
   * Overlap formula (same as the nested relationship filter):
   *
   *   COALESCE(end, start) >= from
   *   AND
   *   COALESCE(start, end) <= to
   *
   * Expanded into Search API condition groups:
   *
   *   AND(
   *     OR( end >= from,  AND(end IS NULL, start >= from) ),
   *     OR( start <= to,  AND(start IS NULL, end <= to)   )
   *   )
   */
  public function query(): void {
    $query = $this->getQuery();
    if (!$query instanceof SearchApiQuery) {
      return;
    }

    $start_field = $this->options['start_field'] ?? '';
    $end_field = $this->options['end_field'] ?? '';

    if (!$start_field || !$end_field) {
      return;
    }

    $values = is_array($this->value) ? $this->value : [];
    $from_val = $this->sanitizeRangeValue((string) ($values['from'] ?? ''));
    $to_val = $this->sanitizeRangeValue((string) ($values['to'] ?? ''));

    if ($from_val === '' && $to_val === '') {
      return;
    }

    // For date-type fields, convert plain year integers (from the select_range
    // widget) to ISO 8601 date strings that Elasticsearch can compare.
    $index = $this->getIndex();
    if ($index instanceof Index) {
      $field = $index->getField($start_field);
      if ($field && $field->getType() === 'date') {
        if ($from_val !== '' && is_numeric($from_val)) {
          $from_val = $this->convertYearToDateString((int) $from_val, '>=');
        }
        if ($to_val !== '' && is_numeric($to_val)) {
          $to_val = $this->convertYearToDateString((int) $to_val, '<=');
        }
      }
    }

    // Build the overlap condition group.
    $overlap = $query->createConditionGroup('AND');

    if ($from_val !== '') {
      // COALESCE(end, start) >= from:
      //   end >= from  OR  (end IS NULL AND start >= from)
      $from_or = $query->createConditionGroup('OR');
      $from_or->addCondition($end_field, $from_val, '>=');

      $end_missing = $query->createConditionGroup('AND');
      $end_missing->addCondition($end_field, NULL, '=');
      $end_missing->addCondition($start_field, $from_val, '>=');
      $from_or->addConditionGroup($end_missing);

      $overlap->addConditionGroup($from_or);
    }

    if ($to_val !== '') {
      // COALESCE(start, end) <= to:
      //   start <= to  OR  (start IS NULL AND end <= to)
      $to_or = $query->createConditionGroup('OR');
      $to_or->addCondition($start_field, $to_val, '<=');

      $start_missing = $query->createConditionGroup('AND');
      $start_missing->addCondition($start_field, NULL, '=');
      $start_missing->addCondition($end_field, $to_val, '<=');
      $to_or->addConditionGroup($start_missing);

      $overlap->addConditionGroup($to_or);
    }

    $query->addConditionGroup($overlap);
  }


  // -------------------------------------------------------------------------
  // Admin summary
  // -------------------------------------------------------------------------

  /**
   * {@inheritdoc}
   */
  public function adminSummary(): string {
    $start = $this->options['start_field'] ?? '';
    $end = $this->options['end_field'] ?? '';

    if (!$start || !$end) {
      return (string) $this->t('Not configured');
    }

    return (string) $this->t('@start → @end', ['@start' => $start, '@end' => $end]);
  }


  // -------------------------------------------------------------------------
  // Helpers
  // -------------------------------------------------------------------------

  /**
   * Returns all range-capable fields from the current Search API index.
   *
   * "Range-capable" means the field type supports <, <=, >=, > operators:
   * date, integer, decimal, and float.
   *
   * @return array
   *   Keyed by field ID, valued as "Label [field_id]" for human display.
   */
  protected function getRangeCapableFields(): array {
    $index = $this->getIndex();
    if (!$index instanceof Index) {
      return [];
    }

    $range_types = ['date', 'integer', 'decimal', 'float'];
    $fields = [];

    foreach ($index->getFields() as $field_id => $field) {
      if (in_array($field->getType(), $range_types, TRUE)) {
        $fields[$field_id] = $field->getLabel() . ' [' . $field_id . ']';
      }
    }

    return $fields;
  }


  /**
   * Strips HTML, trims whitespace, and limits a value to 255 characters.
   */
  protected function sanitizeRangeValue(string $value): string {
    return mb_substr(trim(strip_tags($value)), 0, 255);
  }


  /**
   * Converts a plain year integer to an ISO 8601 date string.
   *
   * Date fields in Elasticsearch are stored as ISO 8601 strings
   * (via PHP's date('c', $timestamp)).  The select_range widget emits plain
   * year integers, so we convert them to the correct boundary:
   *   >= / >  → Jan 1 of that year at 00:00:00
   *   <= / <  → Dec 31 of that year at 23:59:59
   *
   * @param int $year
   *   Year from the filter widget.
   * @param string $operator
   *   Comparison operator.
   *
   * @return string
   *   ISO 8601 date string, e.g. "1800-01-01T00:00:00+00:00".
   */
  protected function convertYearToDateString(int $year, string $operator): string {
    if (in_array($operator, ['<=', '<'], TRUE)) {
      return date('c', mktime(23, 59, 59, 12, 31, $year));
    }
    return date('c', mktime(0, 0, 0, 1, 1, $year));
  }


  /**
   * Builds an ordered array of integer options for the select_range widget.
   *
   * Options are listed in descending order (newest year first).
   *
   * @param array $int_range
   *   Configuration array with keys: min, max, use_current_year_min,
   *   use_current_year_max.
   *
   * @return array
   *   Array keyed and valued by integer, e.g. [2024 => 2024, 2023 => 2023, …].
   */
  protected function buildIntRangeOptions(array $int_range): array {
    $min = ($int_range['use_current_year_min'] ?? FALSE)
      ? (int) date('Y')
      : (isset($int_range['min']) && $int_range['min'] !== '' ? (int) $int_range['min'] : NULL);

    $max = ($int_range['use_current_year_max'] ?? FALSE)
      ? (int) date('Y')
      : (isset($int_range['max']) && $int_range['max'] !== '' ? (int) $int_range['max'] : NULL);

    if ($min === NULL || $max === NULL) {
      return [];
    }

    if ($min > $max) {
      [$min, $max] = [$max, $min];
    }

    $values = array_reverse(range($min, $max));
    return array_combine($values, $values);
  }

}