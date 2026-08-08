<?php

namespace App\Enums;

/**
 * Types of Operations Centre work items.
 *
 * The value is the stable "kind" persisted to the DB and used as the
 * dedupe-key prefix. Never rename an existing case — deprecate + add.
 */
enum OperationsType: string
{
    // Fulfilment lifecycle
    case OrderPaidAwaitingFulfilment = 'order.paid_awaiting_fulfilment';
    case OrderPaidUnviewed = 'order.paid_unviewed';
    case OrderWaiting15 = 'order.waiting_15m';
    case OrderWaiting30 = 'order.waiting_30m';
    case OrderWaiting60 = 'order.waiting_60m';
    case OrderInProgressTooLong = 'order.in_progress_too_long';
    case OrderCredentialsUnopened = 'order.credentials_unopened';

    // Referrals + rewards
    case ReferralConversionAwaitingApproval = 'referrals.conversion_awaiting_approval';
    case RewardAwaitingApproval = 'rewards.awaiting_approval';
    case RewardApprovedAwaitingPayment = 'rewards.approved_awaiting_payment';
    case RewardPaidFundingCompromised = 'rewards.paid_funding_compromised';

    // Provider / risk / infra
    case ProviderVerificationFailure = 'provider.verification_failure';
    case RefundRequest = 'billing.refund_request';
    case Chargeback = 'billing.chargeback';
    case FraudFlag = 'risk.fraud_flag';
    case FailedNotification = 'infra.failed_notification';
    case FailedJob = 'infra.failed_job';

    public function label(): string
    {
        return match ($this) {
            self::OrderPaidAwaitingFulfilment => 'Paid order awaiting fulfilment',
            self::OrderPaidUnviewed => 'Paid order not yet viewed by an admin',
            self::OrderWaiting15 => 'Paid order waiting 15+ minutes',
            self::OrderWaiting30 => 'Paid order waiting 30+ minutes',
            self::OrderWaiting60 => 'Paid order waiting 60+ minutes',
            self::OrderInProgressTooLong => 'Order in progress too long',
            self::OrderCredentialsUnopened => 'Completed credentials not opened by customer',
            self::ReferralConversionAwaitingApproval => 'Referral conversion awaiting approval',
            self::RewardAwaitingApproval => 'Reward claim awaiting approval',
            self::RewardApprovedAwaitingPayment => 'Approved reward awaiting payment',
            self::RewardPaidFundingCompromised => 'Paid reward funding compromised (refund/chargeback)',
            self::ProviderVerificationFailure => 'Provider verification failure',
            self::RefundRequest => 'Refund request',
            self::Chargeback => 'Chargeback',
            self::FraudFlag => 'Fraud flag',
            self::FailedNotification => 'Failed notification',
            self::FailedJob => 'Failed job requires intervention',
        };
    }

    public function defaultPriority(): OperationsPriority
    {
        return match ($this) {
            self::Chargeback,
            self::FraudFlag,
            self::ProviderVerificationFailure,
            self::RewardPaidFundingCompromised,
            self::OrderWaiting60 => OperationsPriority::Critical,

            self::RefundRequest,
            self::OrderWaiting30,
            self::OrderInProgressTooLong,
            self::FailedJob,
            self::FailedNotification,
            self::RewardApprovedAwaitingPayment => OperationsPriority::High,

            self::OrderPaidAwaitingFulfilment,
            self::OrderPaidUnviewed,
            self::OrderWaiting15,
            self::ReferralConversionAwaitingApproval,
            self::RewardAwaitingApproval => OperationsPriority::Medium,

            self::OrderCredentialsUnopened => OperationsPriority::Low,
        };
    }
}
