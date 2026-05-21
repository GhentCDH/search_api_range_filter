<?php

namespace Drupal\views_range_filter\Plugin\views\filter;

use Drupal\Core\Form\FormStateInterface;
use Drupal\views\Plugin\views\filter\FilterPluginBase;

/**
 * Abstract base for range-overlap Views filters.
 *
 * Subclasses implement getFieldOptions(), resolveAutoMinMax(), and query().
 */
abstract class RangeFilterBase extends FilterPluginBase {

  // ---------------------------------------------------------------------------
  // Options
  // ---------------------------------------------------------------------------

  public function defineOptions(): array {
    $options = parent::defineOptions();
    $options['value']             = ['default' => ['from' => '', 'to' => '']];
    $options['start_field']       = ['default' => ''];
    $options['end_field']         = ['default' => ''];
    $options['single_field_mode'] = ['default' => FALSE];
    $options['widget']            = ['default' => 'textfield'];
    $options['int_range']         = ['default' => []];
    $options['from_label']        = ['default' => 'From'];
    $options['to_label']          = ['default' => 'To'];
    return $options;
  }

  // ---------------------------------------------------------------------------
  // Admin configuration form
  // ---------------------------------------------------------------------------

  public function buildOptionsForm(&$form, FormStateInterface $form_state): void {
    parent::buildOptionsForm($form, $form_state);

    if (isset($form['value'])) {
      $form['value']['#access'] = FALSE;
    }

    $field_options = $this->getFieldOptions();

    if (empty($field_options)) {
      $form['range_config_message'] = [
        '#type'   => 'markup',
        '#markup' => '<p class="messages messages--warning">' . $this->getNoFieldsMessage() . '</p>',
      ];
      return;
    }

    $single_mode_name = 'options[range_config][single_field_mode]';

    $form['range_config'] = [
      '#type'  => 'details',
      '#title' => $this->t('Range filter configuration'),
      '#open'  => TRUE,
    ];

    $form['range_config']['single_field_mode'] = [
      '#type'          => 'checkbox',
      '#title'         => $this->t('Single field mode'),
      '#default_value' => $this->options['single_field_mode'] ?? FALSE,
      '#description'   => $this->t(
        'Enable when records have one date/value field rather than a start+end pair. '
        . 'The filter will match records where that single field falls within the selected range.'
      ),
    ];

    $form['range_config']['start_field'] = [
      '#type'          => 'select',
      '#title'         => $this->t('Start field'),
      '#options'       => $field_options,
      '#default_value' => $this->options['start_field'],
      '#empty_option'  => $this->t('- Select -'),
      '#required'      => TRUE,
      // Label updates via JS are possible but complex; keep it simple.
    ];

    $form['range_config']['end_field'] = [
      '#type'          => 'select',
      '#title'         => $this->t('End field'),
      '#options'       => $field_options,
      '#default_value' => $this->options['end_field'],
      '#empty_option'  => $this->t('- Select -'),
      '#description'   => $this->t(
        'Field holding the end of the range. Records where this is empty still match '
        . 'if the start field satisfies the condition.'
      ),
      // Hidden in single-field mode.
      '#states' => [
        'visible'  => [':input[name="' . $single_mode_name . '"]' => ['checked' => FALSE]],
        'required' => [':input[name="' . $single_mode_name . '"]' => ['checked' => FALSE]],
      ],
    ];

    $form['range_config']['from_label'] = [
      '#type'          => 'textfield',
      '#title'         => $this->t('"From" label'),
      '#default_value' => $this->options['from_label'] ?: $this->t('From'),
      '#size'          => 20,
    ];

    $form['range_config']['to_label'] = [
      '#type'          => 'textfield',
      '#title'         => $this->t('"To" label'),
      '#default_value' => $this->options['to_label'] ?: $this->t('To'),
      '#size'          => 20,
    ];

    $form['range_config']['widget'] = [
      '#type'          => 'radios',
      '#title'         => $this->t('Widget type'),
      '#options'       => [
        'textfield'    => $this->t('Text field'),
        'select_range' => $this->t('Dropdown (consecutive integer range)'),
      ],
      '#default_value' => $this->options['widget'],
      '#description'   => $this->t('Use <em>Dropdown</em> for numeric year ranges.'),
    ];

    $this->buildIntRangeSubForm($form['range_config'], $this->options['int_range']);
  }

  protected function buildIntRangeSubForm(array &$parent, array $saved): void {
    $widget_name    = 'options[range_config][widget]';
    $auto_name      = 'options[range_config][int_range][use_auto_range]';
    $cur_year_min   = 'options[range_config][int_range][use_current_year_min]';
    $cur_year_max   = 'options[range_config][int_range][use_current_year_max]';

    $range_visible = [
      'visible' => [':input[name="' . $widget_name . '"]' => ['value' => 'select_range']],
    ];

    // Manual min/max inputs are hidden when auto-range or current-year is active.
    $min_state = [
      'visible' => [
        ':input[name="' . $widget_name . '"]'   => ['value' => 'select_range'],
        ':input[name="' . $auto_name . '"]'     => ['checked' => FALSE],
        ':input[name="' . $cur_year_min . '"]'  => ['checked' => FALSE],
      ],
      'required' => [
        ':input[name="' . $widget_name . '"]'   => ['value' => 'select_range'],
        ':input[name="' . $auto_name . '"]'     => ['checked' => FALSE],
        ':input[name="' . $cur_year_min . '"]'  => ['checked' => FALSE],
      ],
    ];

    $max_state = [
      'visible' => [
        ':input[name="' . $widget_name . '"]'   => ['value' => 'select_range'],
        ':input[name="' . $auto_name . '"]'     => ['checked' => FALSE],
        ':input[name="' . $cur_year_max . '"]'  => ['checked' => FALSE],
      ],
      'required' => [
        ':input[name="' . $widget_name . '"]'   => ['value' => 'select_range'],
        ':input[name="' . $auto_name . '"]'     => ['checked' => FALSE],
        ':input[name="' . $cur_year_max . '"]'  => ['checked' => FALSE],
      ],
    ];

    $cur_year_state = [
      'visible' => [
        ':input[name="' . $widget_name . '"]' => ['value' => 'select_range'],
        ':input[name="' . $auto_name . '"]'   => ['checked' => FALSE],
      ],
    ];

    $parent['int_range'] = ['#type' => 'container'];

    $parent['int_range']['use_auto_range'] = [
      '#type'          => 'checkbox',
      '#title'         => $this->t('Auto-calculate range from data'),
      '#default_value' => $saved['use_auto_range'] ?? FALSE,
      '#description'   => $this->t(
        'Automatically determine minimum and maximum values from the actual data. '
        . 'Results are cached for one hour. Falls back to manual values if the data cannot be queried.'
      ),
      '#states'        => $range_visible,
    ];

    $parent['int_range']['min'] = [
      '#type'          => 'number',
      '#title'         => $this->t('Minimum value'),
      '#default_value' => $saved['min'] ?? 1,
      '#size'          => 10,
      '#states'        => $min_state,
    ];

    $parent['int_range']['use_current_year_min'] = [
      '#type'          => 'checkbox',
      '#title'         => $this->t('Use current year as minimum'),
      '#default_value' => $saved['use_current_year_min'] ?? FALSE,
      '#states'        => $cur_year_state,
    ];

    $parent['int_range']['max'] = [
      '#type'          => 'number',
      '#title'         => $this->t('Maximum value'),
      '#default_value' => $saved['max'] ?? (int) date('Y'),
      '#size'          => 10,
      '#states'        => $max_state,
    ];

    $parent['int_range']['use_current_year_max'] = [
      '#type'          => 'checkbox',
      '#title'         => $this->t('Use current year as maximum'),
      '#default_value' => $saved['use_current_year_max'] ?? TRUE,
      '#states'        => $cur_year_state,
    ];
  }

  public function validateOptionsForm(&$form, FormStateInterface $form_state): void {
    parent::validateOptionsForm($form, $form_state);

    $config      = $form_state->getValue(['options', 'range_config']) ?? [];
    $start       = $config['start_field'] ?? '';
    $end         = $config['end_field']   ?? '';
    $single_mode = !empty($config['single_field_mode']);

    // Same-field is only an error in two-field mode.
    if ($start && $end && $start === $end && !$single_mode) {
      $form_state->setError(
        $form['range_config']['end_field'],
        $this->t('The start field and end field must be different.')
      );
    }

    if (($config['widget'] ?? '') === 'select_range') {
      $ir = $config['int_range'] ?? [];

      // Skip manual min/max validation when auto-range is active.
      if (empty($ir['use_auto_range'])) {
        if (empty($ir['use_current_year_min']) && ($ir['min'] === '' || $ir['min'] === NULL)) {
          $form_state->setError($form['range_config']['int_range']['min'],
            $this->t('A minimum value is required (or enable auto-calculate / current year).'));
        }
        if (empty($ir['use_current_year_max']) && ($ir['max'] === '' || $ir['max'] === NULL)) {
          $form_state->setError($form['range_config']['int_range']['max'],
            $this->t('A maximum value is required (or enable auto-calculate / current year).'));
        }
      }
    }
  }

  public function submitOptionsForm(&$form, FormStateInterface $form_state): void {
    parent::submitOptionsForm($form, $form_state);

    $config = $form_state->getValue(['options', 'range_config']) ?? [];

    foreach (['start_field', 'end_field', 'single_field_mode', 'widget', 'int_range', 'from_label', 'to_label'] as $key) {
      if (array_key_exists($key, $config)) {
        $this->options[$key] = $config[$key];
      }
    }

    // In single-field mode, end_field always mirrors start_field.
    if (!empty($this->options['single_field_mode'])) {
      $this->options['end_field'] = $this->options['start_field'];
    }
  }

  // ---------------------------------------------------------------------------
  // Exposed input
  // ---------------------------------------------------------------------------

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

  // ---------------------------------------------------------------------------
  // Exposed widget
  // ---------------------------------------------------------------------------

  protected function valueForm(&$form, FormStateInterface $form_state): void {
    $form['value']['#tree'] = TRUE;

    $widget     = $this->options['widget'] ?? 'textfield';
    $from_label = $this->options['from_label'] ?: $this->t('From');
    $to_label   = $this->options['to_label']   ?: $this->t('To');

    $values   = is_array($this->value) ? $this->value : [];
    $from_val = $values['from'] ?? '';
    $to_val   = $values['to']   ?? '';

    if ($widget === 'select_range') {
      $options = $this->buildIntRangeOptions($this->options['int_range'] ?? []);

      $form['value']['from'] = ['#type' => 'select', '#title' => $from_label, '#options' => $options, '#default_value' => $from_val, '#empty_option' => $this->t('- Any -')];
      $form['value']['to']   = ['#type' => 'select', '#title' => $to_label,   '#options' => $options, '#default_value' => $to_val,   '#empty_option' => $this->t('- Any -')];
    }
    else {
      $form['value']['from'] = ['#type' => 'textfield', '#title' => $from_label, '#default_value' => $from_val, '#size' => 20];
      $form['value']['to']   = ['#type' => 'textfield', '#title' => $to_label,   '#default_value' => $to_val,   '#size' => 20];
    }
  }

  // ---------------------------------------------------------------------------
  // Admin summary
  // ---------------------------------------------------------------------------

  public function adminSummary(): string {
    $start = $this->options['start_field'] ?? '';
    $end   = $this->options['end_field']   ?? '';

    if (!$start) {
      return (string) $this->t('Not configured');
    }

    if (!empty($this->options['single_field_mode'])) {
      return (string) $this->t('@field (single)', ['@field' => $this->fieldLabel($start)]);
    }

    return (string) $this->t('@start → @end', [
      '@start' => $this->fieldLabel($start),
      '@end'   => $this->fieldLabel($end),
    ]);
  }

  protected function fieldLabel(string $key): string {
    return $key;
  }

  // ---------------------------------------------------------------------------
  // Shared helpers
  // ---------------------------------------------------------------------------

  protected function sanitizeRangeValue(string $value): string {
    return mb_substr(trim(strip_tags($value)), 0, 255);
  }

  /**
   * Builds the integer options array for the dropdown widget.
   *
   * When use_auto_range is set, calls resolveAutoMinMax() and extracts years
   * from whatever format the backend returns (Unix timestamp, ISO 8601, int).
   */
  protected function buildIntRangeOptions(array $int_range): array {
    if (!empty($int_range['use_auto_range'])) {
      $field_id = $this->options['start_field'] ?? '';
      $auto     = $this->resolveAutoMinMax($field_id);

      if ($auto !== NULL) {
        $field_type = $auto['type'] ?? 'integer';

        $int_range['min'] = $this->extractYearOrInt($auto['min'], $field_type);
        $int_range['max'] = $this->extractYearOrInt($auto['max'], $field_type);
        $int_range['use_current_year_min'] = FALSE;
        $int_range['use_current_year_max'] = FALSE;
      }
      // If resolveAutoMinMax() returned NULL, fall through to manual values.
    }

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

  protected function extractYearOrInt(mixed $value, string $field_type = 'integer'): int {
    if ($field_type !== 'date') {
      return (int) $value;
    }

    // Date field: value is either a Unix timestamp (int/numeric) or ISO 8601 string.
    if (is_numeric($value)) {
      return (int) date('Y', (int) $value);
    }

    try {
      return (int) (new \DateTime((string) $value))->format('Y');
    }
    catch (\Exception $e) {
      return (int) substr((string) $value, 0, 4);
    }
  }
  // ---------------------------------------------------------------------------
  // Abstract contract
  // ---------------------------------------------------------------------------

  /**
   * Returns available fields for start/end field selectors.
   */
  abstract protected function getFieldOptions(): array;

  protected function getNoFieldsMessage(): string {
    return (string) $this->t('No suitable fields found.');
  }

  /**
   * Returns the actual min and max values for a field from the underlying data.
   *
   * Implementations should cache their result (keyed by field ID) to avoid
   * extra queries on every page render. Return NULL when not supported or when
   * the query fails; buildIntRangeOptions() will fall back to manual values.
   *
   * @param string $field_id
   *   The field identifier as used by this plugin (SAPI field ID or
   *   "table::column" for SQL).
   *
   * @return array{min: mixed, max: mixed}|null
   */
  protected function resolveAutoMinMax(string $field_id): ?array {
    return NULL;
  }

}