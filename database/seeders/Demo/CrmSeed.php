<?php

declare(strict_types=1);

namespace Database\Seeders\Demo;

use App\Actions\Crm\CrmSettings;
use App\Actions\Crm\ManageCampaigns;
use App\Actions\Crm\ManageLoyalty;
use App\Actions\Crm\ManageMessaging;
use App\Actions\Crm\ManageRelationships;
use App\Domain\Crm\LeadStage;
use App\Domain\Crm\Reminders\ReminderKind;
use App\Models\CrmLead;
use App\Models\CustomerAccount;
use App\Models\DetailingPackage;
use App\Models\User;
use App\Tenancy\TenantContextResolver;
use App\Tenancy\TenantManager;
use Carbon\CarbonImmutable;
use RuntimeException;

/**
 * The CRM's demo data (Phase 11): settings, the message templates and the
 * four reminder rules, two membership tiers (the VIP tier is the source's VIP
 * perks: 10% off and priority), a prepaid plan, a small pipeline, a note, two
 * tasks, two segments and a draft campaign.
 *
 * It SENDS nothing, SELLS nothing and enrols nobody: no message, no ledger
 * entry and no discount on any earlier phase's figure. Reminders are built
 * only by the schedule or by "Run now".
 *
 * Ids: `crm:template:<slug>`, `crm:tier:<slug>`, `crm:plan:<slug>`,
 * `crm:lead:<slug>`, `crm:segment:<slug>`, `crm:campaign:<slug>`,
 * `crm:note:actimed`, `crm:task:actimed`.
 */
final class CrmSeed
{
    /** slug => [name, channel, purpose, subject, body] */
    private const array TEMPLATES = [
        'service-due' => ['Service due', 'email', 'service_reminder', '{{vehicle}} is {{status}} for service', "Hi {{customer_name}},\n\n{{vehicle}} is {{status}} for: {{services}} ({{due_in}}).\n\nBook a slot here: {{link}}\n\n{{shop}} · {{shop_phone}}\n\nStop these reminders: {{unsubscribe_link}}"],
        'document-expiring' => ['Document expiring', 'email', 'service_reminder', '{{document}} is {{status}}', "Hi {{customer_name}},\n\n{{document}} for {{vehicle}} is {{status}} ({{expires_on}}).\n\nNeed help renewing? {{link}}\n\n{{shop}}\n\nStop these reminders: {{unsubscribe_link}}"],
        'detailing-again' => ['Time for another detail', 'email', 'service_reminder', 'Time for another {{service}}?', "Hi {{customer_name}},\n\n{{vehicle}} had its last {{service}} on {{last_visit}}. Ready for the next one?\n\nBook here: {{link}}\n\n{{shop}} · {{shop_phone}}\n\nStop these reminders: {{unsubscribe_link}}"],
        'we-miss-you' => ['We miss you', 'email', 'service_reminder', 'It has been a while', "Hi {{customer_name}},\n\nWe last saw you on {{last_visit}}. Anything your vehicle needs?\n\nBook here: {{link}}\n\n{{shop}}\n\nStop these reminders: {{unsubscribe_link}}"],
        'feedback' => ['How did we do?', 'email', 'feedback', 'How did we do?', "Hi {{customer_name}},\n\nThank you for your payment of invoice {{invoice}}. How likely are you to recommend {{shop}}, from 0 to 10?\n\n{{link}}\n\nStop these messages: {{unsubscribe_link}}"],
        'rainy-season' => ['Rainy season promo', 'email', 'marketing', 'Rainy-season care from {{shop}}', "Hi {{customer_name}},\n\nUndercarriage wash and wax, 15% off this month at {{shop}}.\n\nUnsubscribe: {{unsubscribe_link}}"],
    ];

    private const array RULES = ['pms_due' => 'service-due', 'document_expiry' => 'document-expiring', 'detailing_repeat' => 'detailing-again', 'lapsed_customer' => 'we-miss-you'];

    /**
     * @param  array<string, string>  $ids  source id → ULID, extended in place
     */
    public static function run(array &$ids): void
    {
        $owner = User::query()->findOrFail($ids['owner@mekanikomore.ph'] ?? throw new RuntimeException('CrmSeed needs the demo owner.'));
        $context = app(TenantContextResolver::class)->resolve($owner, null)->context ?? throw new RuntimeException('The demo owner has no tenant context.');

        app(TenantManager::class)->actingAs($context, function () use (&$ids): void {
            app(CrmSettings::class)->current();
            $messaging = app(ManageMessaging::class);
            foreach (self::TEMPLATES as $slug => [$name, $channel, $purpose, $subject, $body]) {
                $ids["crm:template:{$slug}"] = $messaging->saveTemplate(null, ['name' => $name, 'channel' => $channel, 'purpose' => $purpose, 'subject' => $subject, 'body' => $body])->id;
            }
            foreach (self::RULES as $kind => $template) {
                $messaging->saveRule(ReminderKind::from($kind), ['template_id' => $ids["crm:template:{$template}"], 'enabled' => true, 'lead_days' => 30]);
            }

            $loyalty = app(ManageLoyalty::class);
            $ids['crm:tier:vip'] = $loyalty->saveTier(null, ['name' => 'VIP', 'discount_pct' => '10', 'priority_booking' => true, 'perks' => 'Complimentary coffee in the lounge', 'position' => 10])->id;
            $ids['crm:tier:fleet-partner'] = $loyalty->saveTier(null, ['name' => 'Fleet Partner', 'discount_pct' => '5', 'priority_booking' => false, 'perks' => 'Monthly statement, priority on batch work', 'position' => 20])->id;

            $essential = DetailingPackage::query()->findOrFail($ids['detailing:package:essential'] ?? throw new RuntimeException('CrmSeed needs The Essential.'));
            // The Essential is a wash: due again every two weeks.
            $essential->forceFill(['repeat_interval_days' => 14])->save();
            $ids['crm:plan:wash-pass'] = $loyalty->savePlan(null, ['name' => 'Wash Pass (5 × The Essential)', 'price_cents' => 500_000, 'credits' => 5, 'detailing_package_id' => $essential->id, 'valid_days' => 180])->id;

            $relationships = app(ManageRelationships::class);
            $today = CarbonImmutable::now('Asia/Manila');
            $leads = [
                'laguna-logistics' => ['Laguna Logistics Corp.', 'Marites Dela Cruz', 'marites@lagunalogistics.example', 4_500_000, LeadStage::QuoteSent, false, 'Follow up on the PMS quote for 12 vans', 3],
                'southpoint-motors' => ['Southpoint Motors (dealer)', 'Jun Villanueva', 'jun@southpointmotors.example', 2_800_000, LeadStage::Negotiating, true, 'Agree the per-unit reconditioning rate', 1],
                'binan-taxi-coop' => ['Biñan Taxi Cooperative', 'Lorna Aquino', null, 1_200_000, LeadStage::New, false, 'Call to introduce the fleet programme', 5],
            ];
            foreach ($leads as $slug => [$company, $contact, $email, $value, $stage, $dealer, $next, $inDays]) {
                $lead = $relationships->saveLead(null, array_filter([
                    'company_name' => $company, 'contact_name' => $contact, 'contact_email' => $email, 'value_cents' => $value,
                    'is_dealer' => $dealer, 'next_step' => $next, 'next_step_on' => $today->addDays($inDays)->toDateString(), 'source' => 'referral',
                ], fn (mixed $value): bool => $value !== null));
                $ids["crm:lead:{$slug}"] = $stage === LeadStage::New ? $lead->id : $relationships->moveLead($lead, $stage)->id;
            }

            $actimed = CustomerAccount::query()->findOrFail($ids['fc-actimed'] ?? throw new RuntimeException('CrmSeed needs Actimed.'));
            $ids['crm:note:actimed'] = $relationships->addNote($actimed, ['body' => 'Prefers Saturday mornings for PMS. Send the quote to purchasing before any work over the auto-approve limit.', 'pinned' => true])->id;
            $ids['crm:task:actimed'] = $relationships->saveTask(null, ['title' => 'Review Actimed\'s Q4 maintenance plan', 'customer_account_id' => $actimed->id, 'kind' => 'follow_up', 'due_on' => $today->addDays(7)->toDateString()])->id;
            $relationships->saveTask(null, ['title' => 'Send the reconditioning rate card', 'lead_id' => CrmLead::query()->findOrFail($ids['crm:lead:southpoint-motors'])->id, 'kind' => 'call', 'due_on' => $today->addDays(1)->toDateString()]);

            $campaigns = app(ManageCampaigns::class);
            $ids['crm:segment:lapsed'] = $campaigns->saveSegment(null, ['name' => 'Not seen for 90 days', 'description' => 'Served before, not in the last three months', 'filters' => ['not_visited_for_days' => 90]])->id;
            $ids['crm:segment:companies'] = $campaigns->saveSegment(null, ['name' => 'Company accounts', 'filters' => ['account_type' => 'company']])->id;
            $ids['crm:campaign:rainy-season'] = $campaigns->saveCampaign(null, ['name' => 'Rainy season 2026', 'segment_id' => $ids['crm:segment:companies'], 'template_id' => $ids['crm:template:rainy-season']])->id;
        });
    }
}
