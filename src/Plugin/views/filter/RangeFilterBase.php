<?php

namespace Drupal\views_range_filter\Plugin\views\filter;

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
    $options['value']       = ['default' => ['min' => '', 'max' => '', 'type' => 'date']];
    $options['start_field'] = ['default' => ''];
    $options['end_field']   = ['default' => ''];
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
        'Select the same field as Start for single-field mode (field >= min AND field <= max). '
        . 'Records with an empty end field still match if the start field satisfies the condition.'
      ),
      '#required'      => TRUE,
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

    foreach (['start_field', 'end_field', 'from_label', 'to_label'] as $key) {
      if (array_key_exists($key, $config)) {
        $this->options[$key] = $config[$key];
      }
    }

    if (array_key_exists('min_value', $config) || array_key_exists('max_value', $config)) {
      $this->options['value'] = [
        'min'  => $config['min_value'] ?? '',
        'max'  => $config['max_value'] ?? '',
        'type' => $config['value_type'] ?? 'date',
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

    if ($rc && empty($this->options['expose']['required'])) {
      $value = $this->value;
      if (is_array($value) && ($value['min'] ?? '') === '' && ($value['max'] ?? '') === '') {
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
