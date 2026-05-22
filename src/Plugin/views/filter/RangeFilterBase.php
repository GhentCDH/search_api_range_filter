<?php

namespace Drupal\views_range_filter\Plugin\views\filter;

use Drupal\Core\Datetime\DrupalDateTime;
use Drupal\Core\Form\FormStateInterface;
use Drupal\views\Plugin\views\filter\FilterPluginBase;

/**
 * Abstract base for range-overlap Views filters.
 *
 * The $mode property ('date' or 'integer') is set by concrete subclasses and
 * controls which fields are offered and whether date conversion is applied.
 */
abstract class RangeFilterBase extends FilterPluginBase {

  /** @var string 'date' or 'integer' — set by concrete subclasses. */
  protected string $mode = 'integer';

  // ---------------------------------------------------------------------------
  // Options
  // ---------------------------------------------------------------------------

  public function defineOptions(): array {
    $options = parent::defineOptions();
    $options['value']       = ['default' => ['from' => '', 'to' => '']];
    $options['start_field'] = ['default' => ''];
    $options['end_field']   = ['default' => ''];
    $options['widget']      = ['default' => 'textfield'];
    $options['granularity'] = ['default' => 'year'];
    $options['int_range']   = ['default' => []];
    $options['from_label']  = ['default' => 'From'];
    $options['to_label']    = ['default' => 'To'];
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

    $is_exposed   = !empty($this->options['exposed']);
    $is_date_mode = $this->mode === 'date';
    $field_options = $this->getFieldOptions();

    if (empty($field_options)) {
      $form['range_config_message'] = [
        '#type'   => 'markup',
        '#markup' => '<p class="messages messages--warning">' . $this->getNoFieldsMessage() . '</p>',
      ];
      return;
    }

    $form['range_config'] = [
      '#type'  => 'details',
      '#title' => $this->t('Range filter configuration'),
      '#open'  => TRUE,
    ];

    // -------------------------------------------------------------------------
    // Field selection
    // -------------------------------------------------------------------------

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
        'Select the same field as Start for single-field mode (field >= from AND field <= to). '
        . 'Records with an empty end field still match if the start field satisfies the condition.'
      ),
      '#required'      => TRUE,
    ];

    // -------------------------------------------------------------------------
    // Date granularity — date filter only
    // -------------------------------------------------------------------------

    if ($is_date_mode) {
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
          'Precision of the entered values. Year expands 1492 to 1492-01-01 00:00:00 – 1492-12-31 23:59:59.'
        ),
      ];
    }

    // -------------------------------------------------------------------------
    // Non-exposed: fixed from/to values entered by the admin
    // -------------------------------------------------------------------------

    if (!$is_exposed) {
      $saved_values = is_array($this->options['value']) ? $this->options['value'] : [];
      $placeholder  = $is_date_mode ? $this->granularityPlaceholder($this->options['granularity'] ?? 'year') : '';

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
    // Exposed-only: widget type, labels, and integer range configuration
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

      $widget_options = [
        'textfield'    => $this->t('Text field'),
        'select_range' => $this->t('Dropdown (consecutive integer range)'),
      ];
      if ($is_date_mode) {
        $widget_options['datelist'] = $this->t('Date/time picker (select lists per granularity)');
      }

      $widget_description = $is_date_mode
        ? $this->t(
            '<em>Text field:</em> free-form input matching the chosen granularity. '
            . '<br><em>Dropdown:</em> consecutive year list; converts years to date boundaries. Maximum 3000 entries. '
            . '<br><em>Date/time picker:</em> Drupal select lists for each date part. Year is a text field.'
          )
        : $this->t(
            '<em>Text field:</em> free-form integer input. '
            . '<br><em>Dropdown:</em> consecutive integer range (min to max). Maximum 3000 entries.'
          );

      $form['range_config']['widget'] = [
        '#type'          => 'radios',
        '#title'         => $this->t('Widget type'),
        '#options'       => $widget_options,
        '#default_value' => $this->options['widget'],
        '#description'   => $widget_description,
      ];

      $this->buildIntRangeSubForm($form['range_config'], $this->options['int_range']);
    }
  }

  protected function buildIntRangeSubForm(array &$parent, array $saved): void {
    $widget_name  = 'options[range_config][widget]';
    $auto_name    = 'options[range_config][int_range][use_auto_range]';
    $cur_year_min = 'options[range_config][int_range][use_current_year_min]';
    $cur_year_max = 'options[range_config][int_range][use_current_year_max]';

    $range_visible = [
      'visible' => [':input[name="' . $widget_name . '"]' => ['value' => 'select_range']],
    ];

    $min_state = [
      'visible' => [
        ':input[name="' . $widget_name . '"]'  => ['value' => 'select_range'],
        ':input[name="' . $auto_name . '"]'    => ['checked' => FALSE],
        ':input[name="' . $cur_year_min . '"]' => ['checked' => FALSE],
      ],
      'required' => [
        ':input[name="' . $widget_name . '"]'  => ['value' => 'select_range'],
        ':input[name="' . $auto_name . '"]'    => ['checked' => FALSE],
        ':input[name="' . $cur_year_min . '"]' => ['checked' => FALSE],
      ],
    ];

    $max_state = [
      'visible' => [
        ':input[name="' . $widget_name . '"]'  => ['value' => 'select_range'],
        ':input[name="' . $auto_name . '"]'    => ['checked' => FALSE],
        ':input[name="' . $cur_year_max . '"]' => ['checked' => FALSE],
      ],
      'required' => [
        ':input[name="' . $widget_name . '"]'  => ['value' => 'select_range'],
        ':input[name="' . $auto_name . '"]'    => ['checked' => FALSE],
        ':input[name="' . $cur_year_max . '"]' => ['checked' => FALSE],
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
        'Automatically determine the minimum and maximum from both fields. '
        . 'Results are cached for one hour — run <code>drush cr</code> after changing fields to refresh. '
        . 'Falls back to manual values if the range exceeds 3000 entries.'
      ),
      '#states'        => $range_visible,
    ];

    $parent['int_range']['min'] = [
      '#type'          => 'number',
      '#title'         => $this->t('Minimum value'),
      '#default_value' => $saved['min'] ?? 1,
      '#size'          => 10,
      '#description'   => $this->t('The difference between maximum and minimum may not exceed 3000.'),
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

    $config = $form_state->getValue(['options', 'range_config']) ?? [];
    $widget = $config['widget'] ?? 'textfield';

    if (!empty($this->options['exposed']) && $widget === 'select_range') {
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

    foreach (['start_field', 'end_field', 'widget', 'granularity', 'int_range', 'from_label', 'to_label'] as $key) {
      if (array_key_exists($key, $config)) {
        $this->options[$key] = $config[$key];
      }
    }

    if (array_key_exists('from_value', $config) || array_key_exists('to_value', $config)) {
      $this->options['value'] = [
        'from' => $config['from_value'] ?? '',
        'to'   => $config['to_value']   ?? '',
      ];
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

    // Convert datelist DrupalDateTime arrays to granularity-formatted strings.
    if ($rc && ($this->options['widget'] ?? '') === 'datelist' && is_array($this->value)) {
      $format = $this->granularityFormat($this->options['granularity'] ?? 'year');
      foreach (['from', 'to'] as $key) {
        $val = $this->value[$key] ?? NULL;
        if (is_array($val) && isset($val['object'])
          && $val['object'] instanceof DrupalDateTime
          && !$val['object']->hasErrors()) {
          $this->value[$key] = $val['object']->format($format);
        }
        else {
          $this->value[$key] = '';
        }
      }
    }

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

    $granularity = $this->effectiveGranularity();
    $widget      = $this->options['widget'] ?? 'textfield';
    $from_label  = $this->options['from_label'] ?: $this->t('From');
    $to_label    = $this->options['to_label']   ?: $this->t('To');
    $values      = is_array($this->value) ? $this->value : [];
    $from_val    = $values['from'] ?? '';
    $to_val      = $values['to']   ?? '';

    if ($widget === 'select_range') {
      $options = $this->buildIntRangeOptions($this->options['int_range'] ?? []);
      $form['value']['from'] = ['#type' => 'select', '#title' => $from_label, '#options' => $options, '#default_value' => $from_val, '#empty_option' => $this->t('- Any -')];
      $form['value']['to']   = ['#type' => 'select', '#title' => $to_label,   '#options' => $options, '#default_value' => $to_val,   '#empty_option' => $this->t('- Any -')];
    }
    elseif ($widget === 'datelist') {
      $gran   = $this->options['granularity'] ?? 'year';
      $parts  = $this->granularityParts($gran);
      $format = $this->granularityFormat($gran);
      foreach (['from' => $from_label, 'to' => $to_label] as $key => $label) {
        $stored  = $values[$key] ?? '';
        $default = NULL;
        if ($stored !== '') {
          try {
            $dt = DrupalDateTime::createFromFormat($format, $stored, new \DateTimeZone('UTC'));
            if ($dt && !$dt->hasErrors()) {
              $default = $dt;
            }
          }
          catch (\Exception $e) {}
        }
        $form['value'][$key] = [
          '#type'            => 'datelist',
          '#title'           => $label,
          '#default_value'   => $default,
          '#date_part_order' => $parts,
          '#date_text_parts' => ['year'],
          '#date_timezone'   => 'UTC',
          '#required'        => FALSE,
        ];
      }
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

    if (!$end || $start === $end) {
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

  protected function granularityParts(string $gran): array {
    return match ($gran) {
      'year'   => ['year'],
      'month'  => ['year', 'month'],
      'day'    => ['year', 'month', 'day'],
      'hour'   => ['year', 'month', 'day', 'hour'],
      'minute' => ['year', 'month', 'day', 'hour', 'minute'],
      'second' => ['year', 'month', 'day', 'hour', 'minute', 'second'],
      default  => ['year'],
    };
  }

  protected function granularityFormat(string $gran): string {
    return match ($gran) {
      'year'   => 'Y',
      'month'  => 'Y-m',
      'day'    => 'Y-m-d',
      'hour'   => 'Y-m-d H',
      'minute' => 'Y-m-d H:i',
      'second' => 'Y-m-d H:i:s',
      default  => 'Y',
    };
  }

  /**
   * Returns 'none' for integer mode; for date mode returns 'year' for the
   * dropdown widget or the configured granularity for text/datelist.
   */
  protected function effectiveGranularity(): string {
    if ($this->mode !== 'date') {
      return 'none';
    }
    if (!empty($this->options['exposed']) && ($this->options['widget'] ?? 'textfield') === 'select_range') {
      return 'year';
    }
    return $this->options['granularity'] ?? 'year';
  }

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
          $dt = \DateTimeImmutable::createFromFormat('Y-m-d H', $value, $tz);
          if (!$dt) {
            return NULL;
          }
          $h = (int) $dt->format('H');
          return $is_lower ? $dt->setTime($h, 0, 0) : $dt->setTime($h, 59, 59);

        case 'minute':
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
    catch (\Exception $e) {}

    return NULL;
  }

  protected function buildIntRangeOptions(array $int_range): array {
    if (!empty($int_range['use_auto_range'])) {
      $start_field = $this->options['start_field'] ?? '';
      $end_field   = $this->options['end_field']   ?? '';
      $single_mode = $start_field === $end_field;

      $start_auto = $start_field ? $this->resolveAutoMinMax($start_field) : NULL;
      $end_auto   = (!$single_mode && $end_field) ? $this->resolveAutoMinMax($end_field) : NULL;

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
        $int_range['min']                  = min($year_vals);
        $int_range['max']                  = max($year_vals);
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

    if ($min < -9999 || $max > 9999 || ($max - $min) > 3000) {
      \Drupal::logger('views_range_filter')->warning(
        'Dropdown range @min–@max exceeds limits; falling back to empty. Clear the data cache and check field configuration.',
        ['@min' => $min, '@max' => $max]
      );
      return [];
    }

    $values = array_reverse(range($min, $max));
    return array_combine($values, $values);
  }

  protected function extractYearOrInt(mixed $value, string $field_type = 'integer'): int {
    if (is_numeric($value)) {
      $int = (int) $value;
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

  protected function resolveAutoMinMax(string $field_id): ?array {
    return NULL;
  }

}
