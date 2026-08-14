<?php

namespace App\Enums;

/**
 * The overall state of an organization's identity provider configuration,
 * shown as a single badge on the configuration panel.
 *
 * Resolved by TeamIdentityProvider::configurationStatus() for a configuration
 * that exists; a missing configuration is NotConfigured by definition and is
 * handled by the caller, since there is no model instance to ask.
 */
enum IdentityProviderConfigurationStatus: string
{
    case NotConfigured = 'not_configured';
    case Incomplete = 'incomplete';
    case Disabled = 'disabled';
    case ConfigurationError = 'configuration_error';
    case Verified = 'verified';
    case ConfiguredNotTested = 'configured_not_tested';

    /**
     * Get the display label for the status.
     */
    public function label(): string
    {
        return match ($this) {
            self::NotConfigured => __('Not configured'),
            self::Incomplete => __('Configuration incomplete'),
            self::Disabled => __('Disabled'),
            self::ConfigurationError => __('Configuration error'),
            self::Verified => __('Connection verified'),
            self::ConfiguredNotTested => __('Configured, not tested'),
        };
    }

    /**
     * Get the Flux badge color for the status.
     */
    public function color(): string
    {
        return match ($this) {
            self::NotConfigured, self::Disabled => 'zinc',
            self::Incomplete, self::ConfiguredNotTested => 'amber',
            self::ConfigurationError => 'red',
            self::Verified => 'green',
        };
    }
}
