<?php

namespace Drupal\views_range_filter\Plugin\views\filter;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Datetime\DrupalDateTime;
use Drupal\Core\Form\FormStateInterface;
use Drupal\views\Plugin\views\filter\FilterPluginBase;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Abstract base for range-overlap Views filters.
 *
 * The $mode property ('date' or 'integer') is set by concrete subclasses and
 * controls which fields are offered and whether date conversion is applied.
 *
 * Overlap semantics:
 *   A record is returned when its range [start, end] partially overlaps the
 *   filter range [min, max]:  record.start <= filter.max  AND  record.end >= filter.min
 *
 *   An empty filter bound is always treated as infinite (open-ended).
 *   How empty record bounds are treated is controlled by single_bound_behaviour.
 */
abstract class RangeFilterBase extends FilterPluginBase {

  /** @var string 'date' or 'integer' — set by concrete subclasses. */
  protected string $mode = 'integer';

  protected TimeInterface $time;

  // ---------------------------------------------------------------------------
  // Options
  // ---------------------------------------------------------------------------

  public function __construct(array $configuration, $plugin_id, $plugin_definition, TimeInterface $time) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->time = $time;
  }

  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static($configuration, $plugin_id, $plugin_definition,
        $container->get('datetime.time'),
    );
  }

  public function defineOptions(): array {
    $options = parent::defineOptions();
    $options['value']                = ['default' => ['min' => '', 'max' => '', 'type' => 'date']];
    $options['start_field']          = ['default' => ''];
    $options['end_field']            = ['default' => ''];
    $options['from_label']           = ['default' => 'From'];
    $options['to_label']             = ['default' => 'To'];
    $options['single_bound_behaviour'] = ['default' => 'equal'];
    $options['missing_bounds_behaviour']     = ['default' => 'exclude'];
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

    $is_exposed    = !empty($this->options['exposed']);
    $is_date_mode  = $this->mode === 'date';
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
        'Select the same field as Start for single-field mode (field >= min AND field <= max). '
        . 'Records with an empty end field still match if the start field satisfies the condition.'
      ),
      '#required'      => TRUE,
    ];

    // -------------------------------------------------------------------------
    // Behaviour options
    // -------------------------------------------------------------------------

    $form['range_config']['single_bound_behaviour'] = [
      '#type'          => 'radios',
      '#title'         => $this->t('Records with exactly one missing field (start or end)'),
      '#options'       => [
        'open'    => $this->t('Open-ended — treat the missing bound as infinite (e.g. an event with no end date extends indefinitely into the future).'),
        'equal'   => $this->t('Point — treat the missing bound as equal to the other field value (e.g. an event with only a start date is treated as a point in time).'),
        'exclude' => $this->t('Exclude — never return records that have a missing start or end field.'),
      ],
      '#default_value' => $this->options['single_bound_behaviour'] ?? 'equal',
    ];

    $form['range_config']['missing_bounds_behaviour'] = [
      '#type'          => 'radios',
      '#title'         => $this->t('Records where both start and end field are missing'),
      '#options'       => [
        'include' => $this->t('Always include — show these records regardless of filter values.'),
        'exclude' => $this->t('Exclude — hide these records when the filter is active.'),
      ],
      '#default_value' => $this->options['missing_bounds_behaviour'] ?? 'exclude',
      '#description'   => $this->t('Has no effect when "Exclude" is chosen above, since all records with any missing field are already excluded.'),
    ];

    // -------------------------------------------------------------------------
    // Non-exposed: fixed min/max values entered by the admin
    // -------------------------------------------------------------------------

    if (!$is_exposed) {
      $saved_values = is_array($this->options['value']) ? $this->options['value'] : [];

      // Value type radios — date mode only, matching core Date filter.
      if ($is_date_mode) {
        $form['range_config']['value_type'] = [
          '#type'          => 'radios',
          '#title'         => $this->t('Value type'),
          '#options'       => [
            'date'   => $this->t('A date in any machine readable format. CCYY-MM-DD HH:MM:SS is preferred.'),
            'offset' => $this->t("An offset from the current time such as '+1 day' or '-2 hours -30 minutes'"),
          ],
          '#default_value' => $saved_values['type'] ?? 'date',
        ];
      }

      $form['range_config']['min_value'] = [
        '#type'          => 'textfield',
        '#title'         => $this->t('From'),
        '#default_value' => $saved_values['min'] ?? '',
        '#size'          => 20,
        '#description'   => $this->t('Fixed lower bound. Leave empty to apply no lower bound.'),
      ];

      $form['range_config']['max_value'] = [
        '#type'          => 'textfield',
        '#title'         => $this->t('To'),
        '#default_value' => $saved_values['max'] ?? '',
        '#size'          => 20,
        '#description'   => $this->t('Fixed upper bound. Leave empty to apply no upper bound.'),
      ];
    }

    // -------------------------------------------------------------------------
    // Exposed-only: labels
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
    }
  }

  public function validateOptionsForm(&$form, FormStateInterface $form_state): void {
    parent::validateOptionsForm($form, $form_state);
  }

  public function submitOptionsForm(&$form, FormStateInterface $form_state): void {
    parent::submitOptionsForm($form, $form_state);

    $config = $form_state->getValue(['options', 'range_config']) ?? [];

    foreach (['start_field', 'end_field', 'from_label', 'to_label', 'single_bound_behaviour', 'missing_bounds_behaviour'] as $key) {
      if (array_key_exists($key, $config)) {
        $this->options[$key] = $config[$key];
        $form_state->setValue(['options', $key], $config[$key]);
      }
    }

    if (array_key_exists('min_value', $config) || array_key_exists('max_value', $config)) {
      $new_value = [
        'min'  => $config['min_value'] ?? '',
        'max'  => $config['max_value'] ?? '',
        'type' => $config['value_type'] ?? 'date',
      ];
      $this->options['value'] = $new_value;
      $form_state->setValue(['options', 'value'], $new_value);
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
    if (!$rc) {
      return FALSE;
    }

    $value    = $this->value;
    $min      = is_array($value) ? $this->sanitizeRangeValue((string) ($value['min'] ?? '')) : '';
    $max      = is_array($value) ? $this->sanitizeRangeValue((string) ($value['max'] ?? '')) : '';
    $required = !empty($this->options['expose']['required']);

    if ($min === '' && $max === '') {
      if ($required) {
        return TRUE; // Views form validation will fire for the required constraint.
      }
      return FALSE; // Both bounds empty → filter inactive, show all records.
    }

    return TRUE;
  }

  // ---------------------------------------------------------------------------
  // Exposed widget
  // ---------------------------------------------------------------------------

  protected function valueForm(&$form, FormStateInterface $form_state) {
    $form['value']['#tree'] = TRUE;

    $from_label = $this->options['from_label'] ?: $this->t('From');
    $to_label   = $this->options['to_label']   ?: $this->t('To');
    $values     = is_array($this->value) ? $this->value : [];
    $min_val    = $values['min'] ?? '';
    $max_val    = $values['max'] ?? '';

    $form['value']['min'] = [
      '#type'          => 'textfield',
      '#title'         => $from_label,
      '#default_value' => $min_val,
      '#size'          => 20,
    ];

    $form['value']['max'] = [
      '#type'          => 'textfield',
      '#title'         => $to_label,
      '#default_value' => $max_val,
      '#size'          => 20,
    ];
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

  /**
   * Parses a raw date string to a Unix timestamp.
   *
   * Bare 4-digit year is expanded to the appropriate day boundary:
   *   min bound: "2020" → 2020-01-01 00:00:00
   *   max bound: "2020" → 2020-12-31 23:59:59
   *
   * @param string $raw   User-supplied date string.
   * @param string $bound 'min' or 'max'.
   *
   * @return int|null  Unix timestamp, or NULL if the string cannot be parsed.
   */
  protected function parseDateBound(string $raw, string $bound): ?int {
    if ($raw === '') {
      return NULL;
    }

    // YYYY → year boundary.
    if (preg_match('/^\d{4}$/', $raw)) {
      $raw = $bound === 'min'
        ? $raw . '-01-01 00:00:00'
        : $raw . '-12-31 23:59:59';
    }
    // YYYY-MM → month boundary (last day via PHP 't' format).
    elseif (preg_match('/^\d{4}-\d{2}$/', $raw)) {
      if ($bound === 'min') {
        $raw .= '-01 00:00:00';
      }
      else {
        try {
          $dt = new DrupalDateTime($raw . '-01', 'UTC');
          if ($dt->hasErrors()) {
            return NULL;
          }
          $raw = $dt->format('Y-m-t') . 'T23:59:59';
        }
        catch (\Throwable $e) {
          return NULL;
        }
      }
    }
    // YYYY-MM-DD → day boundary.
    elseif (preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw)) {
      $raw .= $bound === 'min' ? ' 00:00:00' : ' 23:59:59';
    }
    // YYYY-MM-DD HH:MM → minute boundary (max only; min already at :00).
    elseif ($bound === 'max' && preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $raw)) {
      $raw .= ':59';
    }

    try {
      $dt = new DrupalDateTime($raw, 'UTC');
      return $dt->hasErrors() ? NULL : $dt->getTimestamp();
    }
    catch (\Throwable $e) {
      return NULL;
    }
  }

  // ---------------------------------------------------------------------------
  // Shared DB helper
  // ---------------------------------------------------------------------------

  /**
   * Returns MIN and MAX for a column via a direct database query.
   *
   * Shared by RangeFilterSql (using Drupal's default connection) and by the
   * search_api_db fallback in RangeFilterSapi (using the backend's own
   * connection, which may point to an external database).
   *
   * @return array{min: mixed, max: mixed}|null
   */
  protected function resolveMinMaxFromTable(Connection $db, string $table, string $column): ?array {
    $query = $db->select($table, 't');
    $query->addExpression("MIN(t.$column)", 'min_val');
    $query->addExpression("MAX(t.$column)", 'max_val');
    $row = $query->execute()->fetchObject();

    if (!$row || $row->min_val === NULL) {
      return NULL;
    }

    return ['min' => $row->min_val, 'max' => $row->max_val];
  }

  // ---------------------------------------------------------------------------
  // Abstract contract
  // ---------------------------------------------------------------------------

  abstract protected function getFieldOptions(): array;

  protected function getNoFieldsMessage(): string {
    return (string) $this->t('No suitable fields found.');
  }

  public function resolveAutoMinMax(string $field_id): ?array {
    return NULL;
  }

}
