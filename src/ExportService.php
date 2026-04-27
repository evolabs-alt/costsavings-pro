<?php

namespace CostSavings;

use Dompdf\Dompdf;
use Dompdf\Options;
use PDO;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class ExportService
{
    /**
     * @param array<int, array<string, mixed>> $items
     */
    public static function spreadsheetBytes(array $items): string
    {
        $ss = new Spreadsheet();
        $sh = $ss->getActiveSheet();
        $sh->setTitle('Vendors');
        $rows = [[
            'Vendor', 'Cost per period', 'Frequency', 'Annual cost', 'Status',
            'Visibility', 'Manager ID', 'Purpose', 'Cancel deadline', 'Last payment',
        ]];
        foreach ($items as $it) {
            $status = isset($it['status']) && $it['status'] !== ''
                ? VendorService::normalizeStatus($it['status'])
                : VendorService::resolveStatusFromItem(is_array($it) ? $it : []);
            $rows[] = [
                $it['vendor_name'] ?? '',
                $it['cost_per_period'] ?? 0,
                $it['frequency'] ?? '',
                $it['annual_cost'] ?? 0,
                VendorService::statusLabel($status),
                $it['visibility'] ?? '',
                $it['manager_user_id'] ?? '',
                $it['purpose_of_subscription'] ?? $it['notes'] ?? '',
                $it['cancellation_deadline'] ?? '',
                $it['last_payment_date'] ?? '',
            ];
        }
        $sh->fromArray($rows, null, 'A1');

        ob_start();
        $w = new Xlsx($ss);
        $w->save('php://output');

        return (string) ob_get_clean();
    }

    /**
     * @param array<int, array<string, mixed>> $items
     */
    public static function pdfVendorListHtml(array $items): string
    {
        $html = '<html><head><meta charset="UTF-8"><style>body{font-family:DejaVu Sans,sans-serif;font-size:10px;} table{border-collapse:collapse;width:100%;} th,td{border:1px solid #333;padding:4px;}</style></head><body>';
        $html .= '<h2>Vendor list</h2><table><thead><tr>';
        foreach (['Vendor', 'Annual', 'Frequency', 'Status', 'Purpose'] as $h) {
            $html .= '<th>' . htmlspecialchars($h) . '</th>';
        }
        $html .= '</tr></thead><tbody>';
        foreach ($items as $it) {
            $status = isset($it['status']) && $it['status'] !== ''
                ? VendorService::normalizeStatus($it['status'])
                : VendorService::resolveStatusFromItem(is_array($it) ? $it : []);
            $html .= '<tr>';
            $html .= '<td>' . htmlspecialchars((string) ($it['vendor_name'] ?? '')) . '</td>';
            $html .= '<td>' . htmlspecialchars((string) ($it['annual_cost'] ?? '')) . '</td>';
            $html .= '<td>' . htmlspecialchars((string) ($it['frequency'] ?? '')) . '</td>';
            $html .= '<td>' . htmlspecialchars(VendorService::statusLabel($status)) . '</td>';
            $p = $it['purpose_of_subscription'] ?? $it['notes'] ?? '';
            $html .= '<td>' . htmlspecialchars((string) $p) . '</td>';
            $html .= '</tr>';
        }
        $html .= '</tbody></table></body></html>';

        return $html;
    }

    /**
     * @param array<int, array<string, mixed>> $items
     */
    public static function executiveSummaryHtml(array $items): string
    {
        $totalAnnual = 0.0;
        $pendingCancel = 0.0;
        $confirmed = 0.0;
        foreach ($items as $it) {
            $a = (float) ($it['annual_cost'] ?? 0);
            $totalAnnual += $a;
            $status = isset($it['status']) && $it['status'] !== ''
                ? VendorService::normalizeStatus($it['status'])
                : VendorService::resolveStatusFromItem(is_array($it) ? $it : []);
            if ($status === VendorService::STATUS_MARK) {
                $pendingCancel += $a;
            } elseif ($status === VendorService::STATUS_CANCELLED) {
                $confirmed += $a;
            }
        }

        $html = '<html><head><meta charset="UTF-8"><style>body{font-family:DejaVu Sans,sans-serif;font-size:11px;line-height:1.4;} h1{color:#238FBE;}</style></head><body>';
        $html .= '<h1>Executive summary</h1>';
        $html .= '<p><strong>Total annualized spend (visible vendors):</strong> $' . number_format($totalAnnual, 2) . '</p>';
        $html .= '<p><strong>Potential annual savings (Mark for Cancellation, not yet confirmed):</strong> $' . number_format($pendingCancel, 2) . '</p>';
        $html .= '<p><strong>Confirmed annual savings:</strong> $' . number_format($confirmed, 2) . '</p>';
        $html .= '<p>Review vendor rows for overlap, duplicate subscriptions, and right-sizing opportunities.</p>';
        $html .= '</body></html>';

        return $html;
    }

    public static function htmlToPdfBytes(string $html): string
    {
        $opts = new Options();
        $opts->set('defaultFont', 'DejaVu Sans');
        $opts->set('isRemoteEnabled', false);
        $dompdf = new Dompdf($opts);
        $dompdf->loadHtml($html);
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();

        return $dompdf->output();
    }
}
