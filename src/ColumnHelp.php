<?php

declare(strict_types=1);

namespace CostSavings;

/**
 * Help text for cost-calculator column header info popups.
 *
 * @return array<string, array{title: string, html: string}>
 */
final class ColumnHelp
{
    public static function entriesForJs(): array
    {
        $statusSections = [
            ['Pending', 'Not reviewed yet, or no decision recorded—use this until the team classifies the vendor.'],
            ['Question', 'Needs a follow-up (stakeholder input, clarification, or discussion) before you commit to keep or cancel.'],
            ['Unknown', 'Not enough context to decide—purpose, owner, or numbers are unclear (common right after import or with thin data).'],
            ['Keep', 'Reviewed and you plan to continue this vendor or spend; no cancellation in progress.'],
            ['Mark for Cancellation', 'Decision is to cancel; use the cancellation date field to track the target end until it is fully executed.'],
            ['Cancelled', 'Cancellation is complete (or the contract ended); treat this spend as off for forward-looking savings.'],
        ];
        $statusDl = '';
        foreach ($statusSections as [$label, $desc]) {
            $statusDl .= '<dt>' . htmlspecialchars($label) . '</dt><dd>' . htmlspecialchars($desc) . '</dd>';
        }
        $aiPrefix = VendorPurposeService::AI_PURPOSE_UI_PREFIX;

        return [
            'select' => [
                'title' => 'Select',
                'html' => '<p class="column-help-intro">Use row checkboxes to choose vendors for bulk actions (status, category, manager, visibility, frequency, and more). The header checkbox selects or clears all rows that match your current report and column filters—not rows hidden on other pages.</p>',
            ],
            'item_number' => [
                'title' => 'Item #',
                'html' => '<p class="column-help-intro">The row number shown in the current table view. Numbers update when you filter, sort, or change page size; they reflect display order, not a permanent vendor ID.</p>',
            ],
            'category' => [
                'title' => 'Category',
                'html' => '<p class="column-help-intro">Optional grouping label for this vendor row. Categories belong to the current project. Choose an existing category from the dropdown or create a new one on the fly. Use the filter and sort icons to focus on specific categories or uncategorized rows.</p>',
            ],
            'account' => [
                'title' => 'Account',
                'html' => '<p class="column-help-intro">The imported QuickBooks / CSV GL account(s) for this vendor. When a vendor has activity in more than one account, all unique accounts are shown comma-separated (e.g. Advertising, Software). This value is set by import (not edited in the grid). Use filter and sort to focus on specific accounts.</p>',
            ],
            'vendor' => [
                'title' => 'Vendor',
                'html' => '<p class="column-help-intro">The vendor or subscription name for this spend line. Administrators edit this field; members can view only. Use the filter icon to search by name (case-insensitive). Use the sort icon to order alphabetically. The list icon on a row opens imported raw transaction history for that vendor.</p>',
            ],
            'cost' => [
                'title' => 'Cost',
                'html' => '<p class="column-help-intro">The amount charged per billing period (e.g. one month or one quarter). Administrators edit this field; members can view only. The app uses Cost with Frequency to compute Annual Cost.</p>',
            ],
            'frequency' => [
                'title' => 'Frequency',
                'html' => '<p class="column-help-intro">How often this vendor bills: weekly, monthly, quarterly, semi-annual, annually, or one-off. Administrators edit this field; members can view only. Together with Cost, this drives the annualized spend figure used in savings totals.</p>',
            ],
            'annual_cost' => [
                'title' => 'Annual Cost',
                'html' => '<p class="column-help-intro">Estimated yearly spend, calculated from Cost and Frequency. Sort this column to surface the largest line items and savings opportunities. Mark-for-cancellation and cancelled rows contribute to Potential and Confirmed savings summaries below the table.</p>',
            ],
            'manager' => [
                'title' => 'Manager',
                'html' => '<p class="column-help-intro">The team member responsible for this vendor row. On confidential rows, only the assigned manager (and organization admins) can change status. Use the filter and sort icons to focus on your vendors or unassigned rows.</p>',
            ],
            'visibility' => [
                'title' => 'Visibility',
                'html' => '<p class="column-help-intro"><strong>Public</strong> rows are visible to admins across the organization. <strong>Confidential</strong> rows are limited to the assigned manager and super admins. Members always see public rows plus confidential rows they manage.</p>',
            ],
            'status' => [
                'title' => 'Status',
                'html' => '<p class="column-help-intro">Tracks review and cancellation decisions for each vendor. Savings summaries count rows marked for cancellation or cancelled toward potential and confirmed savings.</p>'
                    . '<dl class="column-help-dl">' . $statusDl . '</dl>',
            ],
            'purpose' => [
                'title' => 'Purpose',
                'html' => '<p class="column-help-intro">A short note on why the organization uses this vendor or subscription. You can enter it manually or auto-populate with AI ('
                    . htmlspecialchars($aiPrefix)
                    . 'marks AI-suggested text). Use <strong>Show Purpose / Hide Purpose</strong> above the table to toggle this column.</p>',
            ],
            'chat' => [
                'title' => 'Chat',
                'html' => '<p class="column-help-intro">Per-vendor thread for team notes and an automatic action log. Status and purpose changes, plus AI purpose updates, are recorded here alongside manual messages. You can edit your own notes within one hour of posting. Type <strong>@name</strong> in a note to tag a teammate (matches username, display name, or email). A red dot means unread manual notes only—automatic log entries do not trigger the badge. An orange chat button means you were tagged in unread notes. Save the row first to enable chat. Use the filter icon to show vendors with <strong>Unread</strong> or <strong>Tagged</strong> messages.</p>',
            ],
        ];
    }
}
