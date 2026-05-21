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
    $options['date_mode']         = ['default' => FALSE];
    $options['widget']            = ['default' => 'textfield'];
    $options['granularity']       = ['default' => 'year'];
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

    // Always hide the parent value element; our own inputs live inside
    // range_config so they always render regardless of exposed state.
    if (isset($form['value'])) {
      $form['value']['#access'] = FALSE;
    }

    $is_exposed = !empty($this->options['exposed']);

    $field_options = $this->getFieldOptions();

    if (empty($field_options)) {
      $form['range_config_message'] = [
        '#type'   => 'markup',
        '#markup' => '<p class="messages messages--warning">' . $this->getNoFieldsMessage() . '</p>',
      ];
      return;
    }

    $single_mode_name = 'options[range_config][single_field_mode]';
    $date_mode_name   = 'options[range_config][date_mode]';
    $widget_name      = 'options[range_config][widget]';

    $form['range_config'] = [
      '#type'  => 'details',
      '#title' => $this->t('Range filter configuration'),
      '#open'  => TRUE,
    ];

    // -------------------------------------------------------------------------
    // Always visible: field selection and mode.
    // -------------------------------------------------------------------------

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
      '#states' => [
        'visible'  => [':input[name="' . $single_mode_name . '"]' => ['checked' => FALSE]],
        'required' => [':input[name="' . $single_mode_name . '"]' => ['checked' => FALSE]],
      ],
    ];

    // -------------------------------------------------------------------------
    // Date mode toggle and granularity.
    // -------------------------------------------------------------------------

    $form['range_config']['date_mode'] = [
      '#type'          => 'checkbox',
      '#title'         => $this->t('Treat range as dates'),
      '#default_value' => $this->options['date_mode'] ?? FALSE,
      '#description'   => $this->t(
        'When enabled, entered values are interpreted as dates and converted to the '
        . 'format the backend expects. Date fields are handled automatically. '
        . 'Integer fields are treated as storing year values and compared as integers. '
        . 'When disabled, all values are compared as-is (numeric / raw).'
      ),
    ];

    $gran_states = [
      'visible' => [':input[name="' . $date_mode_name . '"]' => ['checked' => TRUE]],
    ];

    if ($is_exposed) {
      // Granularity is irrelevant for the dropdown widget (it always emits year integers).
      $gran_states['visible'][':input[name="' . $widget_name . '"]'] = ['value' => 'textfield'];
    }

    $form['range_config']['granularity'] = [
      '#type'          => 'radios',
      '#title'         => $this->t('Date granularity'),
      '#options'       => [
        'year'   => $this->t('Year <small>(e.g. 1492)</small>'),
        'month'  => $this->t('Month <small>(YYYY-MM)</small>'),
        'day'    => $this->t('Day <small>(YYYY-MM-DD)</small>'),
        'hour'   => $this->t('Hour <small>(YYYY-MM-DD HH)</small>'),
        'minute' => $this->t('Minute <small>(YYYY-MM-DD HH:MM)</small>'),
        'second' => $this->t('Second <small>(YYYY-MM-DD HH:MM:SS)</small>'),
      ],
      '#default_value' => $this->options['granularity'] ?? 'year',
      '#description'   => $this->t(
        'Precision of the entered values and the period boundaries to expand them to. '
        . 'For example, Year expands 1492 to 1492-01-01 00:00:00 – 1492-12-31 23:59:59.'
      ),
      '#states'        => $gran_states,
    ];

    // -------------------------------------------------------------------------
    // Non-exposed: fixed from/to values entered by the admin.
    // -------------------------------------------------------------------------

    if (!$is_exposed) {
      $saved_values = is_array($this->options['value']) ? $this->options['value'] : [];
      $gran         = $this->options['granularity'] ?? 'year';
      $date_mode    = !empty($this->options['date_mode']);
      $placeholder  = $date_mode ? $this->granularityPlaceholder($gran) : '';

      $form['range_config']['from_value'] = [
        '#type'          => 'textfield',
        '#title'         => $this->t('From'),
        '#default_value' => $saved_values['from'] ?? '',
        '#placeholder'   => $placeholder,
        '#size'          => 20,
        '#description'   => $this->t('Fixed lower bound. Leave empty to apply no lower bound.'),
      ];

      $form['range_config']['to_value'] = [
        '#type'          => 'textfield',
        '#title'         => $this->t('To'),
        '#default_value' => $saved_values['to'] ?? '',
        '#placeholder'   => $placeholder,
        '#size'          => 20,
        '#description'   => $this->t('Fixed upper bound. Leave empty to apply no upper bound.'),
      ];
    }

    // -------------------------------------------------------------------------
    // Exposed-only: widget, labels, and integer range configuration.
    // -------------------------------------------------------------------------

    if ($is_exposed) {
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
        '#description'   => $this->t('Input widget shown to end users. Use <em>Dropdown</em> for numeric year ranges.'),
      ];

      $this->buildIntRangeSubForm($form['range_config'], $this->options['int_range']);
    }
  }

  protected function buildIntRangeSubForm(array &$parent, array $saved): void {
    $widget_name    = 'options[range_config][widget]';
    $auto_name      = 'options[range_config][int_range][use_auto_range]';
    $cur_year_min   = 'options[range_config][int_range][use_current_year_min]';
    $cur_year_max   = 'options[range_config][int_range][use_current_year_max]';

    $range_visible = [
      'visible' => [':input[name="' . $widget_name . '"]' => ['value' => 'select_range']],
    ];

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

    if ($start && $end && $start === $end && !$single_mode) {
      $form_state->setError(
        $form['range_config']['end_field'],
        $this->t('The start field and end field must be different.')
      );
    }

    if (!empty($this->options['exposed']) && ($config['widget'] ?? '') === 'select_range') {
      $ir = $config['int_range'] ?? [];

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

    foreach (['start_field', 'end_field', 'single_field_mode', 'date_mode', 'widget', 'granularity', 'int_range', 'from_label', 'to_label'] as $key) {
      if (array_key_exists($key, $config)) {
        $this->options[$key] = $config[$key];
      }
    }

    // For non-exposed filters, save the admin-entered fixed values.
    if (array_key_exists('from_value', $config) || array_key_exists('to_value', $config)) {
      $this->options['value'] = [
        'from' => $config['from_value'] ?? '',
        'to'   => $config['to_value']   ?? '',
      ];
    }

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

    $granularity  = $this->effectiveGranularity();
    $use_dropdown = ($this->options['widget'] ?? 'textfield') === 'select_range';

    $from_label = $this->options['from_label'] ?: $this->t('From');
    $to_label   = $this->options['to_label']   ?: $this->t('To');

    $values   = is_array($this->value) ? $this->value : [];
    $from_val = $values['from'] ?? '';
    $to_val   = $values['to']   ?? '';

    if ($use_dropdown) {
      $options = $this->buildIntRangeOptions($this->options['int_range'] ?? []);
      $form['value']['from'] = ['#type' => 'select', '#title' => $from_label, '#options' => $options, '#default_value' => $from_val, '#empty_option' => $this->t('- Any -')];
      $form['value']['to']   = ['#type' => 'select', '#title' => $to_label,   '#options' => $options, '#default_value' => $to_val,   '#empty_option' => $this->t('- Any -')];
    }
    else {
      $placeholder = $this->granularityPlaceholder($granularity);
      $form['value']['from'] = ['#type' => 'textfield', '#title' => $from_label, '#default_value' => $from_val, '#size' => 20, '#placeholder' => $placeholder];
      $form['value']['to']   = ['#type' => 'textfield', '#title' => $to_label,   '#default_value' => $to_val,   '#size' => 20, '#placeholder' => $placeholder];
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
   * Returns a placeholder string for a given granularity.
   */
  protected function granularityPlaceholder(string $granularity): string {
    return match ($granularity) {
      'year'   => (string) $this->t('e.g. 1492'),
      'month'  => 'YYYY-MM',
      'day'    => 'YYYY-MM-DD',
      'hour'   => 'YYYY-MM-DD HH',
      'minute' => 'YYYY-MM-DD HH:MM',
      'second' => 'YYYY-MM-DD HH:MM:SS',
      default  => '',
    };
  }

  /**
   * Returns the active date granularity, or 'none' when date mode is disabled.
   *
   * When the filter is exposed and uses the dropdown widget, granularity is
   * always 'year' (the dropdown emits integer years).
   */
  protected function effectiveGranularity(): string {
    // The dropdown always emits integer years, so year conversion must happen
    // for date fields regardless of the date_mode setting.
    if (!empty($this->options['exposed']) && ($this->options['widget'] ?? 'textfield') === 'select_range') {
      return 'year';
    }
    if (empty($this->options['date_mode'])) {
      return 'none';
    }
    return $this->options['granularity'] ?? 'year';
  }

  /**
   * Parses a user-supplied value and granularity into a UTC DateTimeImmutable.
   *
   * The returned DateTime represents the start or end boundary of the period
   * described by the user's input. Returns NULL when the value cannot be parsed.
   *
   * @param string $value    Raw user input.
   * @param string $gran     year|month|day|hour|minute|second
   * @param bool   $is_lower TRUE for >= (start-of-period), FALSE for <= (end-of-period).
   */
  protected function parseGranularityBoundary(string $value, string $gran, bool $is_lower): ?\DateTimeImmutable {
    $tz = new \DateTimeZone('UTC');

    try {
      switch ($gran) {
        case 'year':
          if (!is_numeric($value)) {
            return NULL;
          }
          $year = (int) $value;
          $str  = sprintf('%04d-%s', $year, $is_lower ? '01-01 00:00:00' : '12-31 23:59:59');
          $dt   = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $str, $tz);
          return $dt ?: NULL;

        case 'month':
          $dt = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $value . '-01 00:00:00', $tz);
          if (!$dt) {
            return NULL;
          }
          if ($is_lower) {
            return $dt;
          }
          $end = $dt->modify('last day of this month');
          return $end ? $end->setTime(23, 59, 59) : NULL;

        case 'day':
          $dt = \DateTimeImmutable::createFromFormat('Y-m-d', $value, $tz);
          if (!$dt) {
            return NULL;
          }
          return $is_lower ? $dt->setTime(0, 0, 0) : $dt->setTime(23, 59, 59);

        case 'hour':
          // Input: YYYY-MM-DD HH
          $dt = \DateTimeImmutable::createFromFormat('Y-m-d H', $value, $tz);
          if (!$dt) {
            return NULL;
          }
          $h = (int) $dt->format('H');
          return $is_lower ? $dt->setTime($h, 0, 0) : $dt->setTime($h, 59, 59);

        case 'minute':
          // Input: YYYY-MM-DD HH:MM
          $dt = \DateTimeImmutable::createFromFormat('Y-m-d H:i', $value, $tz);
          if (!$dt) {
            return NULL;
          }
          $h = (int) $dt->format('H');
          $m = (int) $dt->format('i');
          return $is_lower ? $dt->setTime($h, $m, 0) : $dt->setTime($h, $m, 59);

        case 'second':
          $dt = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $value, $tz);
          return $dt ?: NULL;
      }
    }
    catch (\Exception $e) {
      // Fall through to NULL.
    }

    return NULL;
  }

  /**
   * Builds the integer options array for the dropdown widget.
   *
   * When use_auto_range is set, calls resolveAutoMinMax() and extracts years
   * from whatever format the backend returns (Unix timestamp, ISO 8601, int).
   */
  protected function buildIntRangeOptions(array $int_range): array {
    if (!empty($int_range['use_auto_range'])) {
      $start_field = $this->options['start_field'] ?? '';
      $end_field   = $this->options['end_field']   ?? '';
      $single_mode = !empty($this->options['single_field_mode']) || $start_field === $end_field;

      $start_auto = $start_field ? $this->resolveAutoMinMax($start_field) : NULL;
      $end_auto   = (!$single_mode && $end_field)
        ? $this->resolveAutoMinMax($end_field)
        : NULL;

      // Collect all year values from both fields, then take the overall span.
      $year_vals = [];
      if ($start_auto !== NULL) {
        $t           = $start_auto['type'] ?? 'integer';
        $year_vals[] = $this->extractYearOrInt($start_auto['min'], $t);
        $year_vals[] = $this->extractYearOrInt($start_auto['max'], $t);
      }
      if ($end_auto !== NULL) {
        $t           = $end_auto['type'] ?? 'integer';
        $year_vals[] = $this->extractYearOrInt($end_auto['min'], $t);
        $year_vals[] = $this->extractYearOrInt($end_auto['max'], $t);
      }

      if (!empty($year_vals)) {
        $int_range['min'] = min($year_vals);
        $int_range['max'] = max($year_vals);
        $int_range['use_current_year_min'] = FALSE;
        $int_range['use_current_year_max'] = FALSE;
      }
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

    // Guard against unconverted timestamps or other bad values slipping through:
    // a plausible year range is -9999 to 9999 and at most a few thousand steps.
    if ($min < -9999 || $max > 9999 || ($max - $min) > 2000) {
      \Drupal::logger('views_range_filter')->warning(
        'Dropdown range @min–@max looks like unconverted timestamps; falling back to empty. Clear the Drupal data cache and check field type detection.',
        ['@min' => $min, '@max' => $max]
      );
      return [];
    }

    $values = array_reverse(range($min, $max));
    return array_combine($values, $values);
  }

  /**
   * Converts a raw backend value to an integer year (for the dropdown).
   *
   * Handles Unix timestamps (via DateTime('@ts')), ISO 8601 strings, and plain
   * year integers transparently.
   */
  protected function extractYearOrInt(mixed $value, string $field_type = 'integer'): int {
    if (is_numeric($value)) {
      $int = (int) $value;

      // Convert to year when the type is declared as a date, or when the
      // integer is outside a plausible year range (i.e. it is a timestamp).
      // DateTime('@ts') handles timestamps on 64-bit PHP for any year.
      if ($field_type === 'date' || abs($int) > 9999) {
        try {
          return (int) (new \DateTime('@' . $int))->format('Y');
        }
        catch (\Exception $e) {
          return $int;
        }
      }

      return $int;
    }

    // Non-numeric: treat as an ISO 8601 / DATETIME string.
    try {
      return (int) (new \DateTime((string) $value, new \DateTimeZone('UTC')))->format('Y');
    }
    catch (\Exception $e) {
      return (int) substr((string) $value, 0, 4);
    }
  }

  // ---------------------------------------------------------------------------
  // Abstract contract
  // ---------------------------------------------------------------------------

  abstract protected function getFieldOptions(): array;

  protected function getNoFieldsMessage(): string {
    return (string) $this->t('No suitable fields found.');
  }

  /**
   * Returns the actual min and max values for a field from the underlying data.
   *
   * Return NULL when not supported or when the query fails.
   * buildIntRangeOptions() will fall back to manual values.
   *
   * @return array{min: mixed, max: mixed, type: string}|null
   */
  protected function resolveAutoMinMax(string $field_id): ?array {
    return NULL;
  }

}
