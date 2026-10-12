<?php

namespace App\Livewire\Concerns;

use Illuminate\Support\Str;

/**
 * Checks a field as soon as the user leaves it (the field is bound with
 * wire:model.blur), so a bad email or mobile number is flagged right away
 * and a good one is marked valid - instead of only on submit.
 *
 * The component lists the fields in blurValidated(); the rules come from
 * rulesForBlur(), which defaults to the component's rules().
 */
trait ValidatesOnBlur
{
    /** Fields that passed their last check - shown with a "valid" note. */
    public array $validFields = [];

    abstract protected function blurValidated(): array;

    protected function rulesForBlur(): array
    {
        return $this->rules();
    }

    public function updated(string $property): void
    {
        $this->checkOnBlur($property);
    }

    protected function checkOnBlur(string $property): void
    {
        if (!in_array($property, $this->blurValidated(), true)) {
            return;
        }

        // Emails are stored lowercase; normalize before checking uniqueness
        if (Str::endsWith($property, 'email')) {
            data_set($this, $property, Str::lower(trim((string) data_get($this, $property))));
        }

        $this->validFields = array_values(array_diff($this->validFields, [$property]));

        $rules = $this->rulesForBlur();
        if (!array_key_exists($property, $rules)) {
            return;
        }

        $this->validateOnly($property, [$property => $rules[$property]]);

        // An empty optional field is not worth a "valid" note
        if (filled(data_get($this, $property))) {
            $this->validFields[] = $property;
        }
    }

    public function isValid(string $property): bool
    {
        return in_array($property, $this->validFields, true) && !$this->getErrorBag()->has($property);
    }
}
