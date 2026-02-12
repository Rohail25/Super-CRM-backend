<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Api\Traits\HandlesApiErrors;
use App\Models\Lead;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use PhpOffice\PhpSpreadsheet\IOFactory;

class LeadController extends Controller
{
    use HandlesApiErrors;

    /**
     * Display a listing of leads.
     */
    public function index(Request $request)
    {
        $user = $request->user();
        $companyId = $user->isSuperAdmin() && $request->has('company_id')
            ? $request->company_id
            : ($user->company_id ?? null);

        // Build query based on company_id
        if ($user->isSuperAdmin() && !$request->has('company_id')) {
            // Super admin without company_id filter - show all leads
            $query = Lead::query();
        } elseif ($companyId === null) {
            // User with no company_id - show only leads with null company_id
            $query = Lead::whereNull('company_id');
        } else {
            // User with company_id - show only their company's leads
            $query = Lead::where('company_id', $companyId);
        }

        // Apply search filter
        if ($request->has('search') && $request->search) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('email', 'like', "%{$search}%")
                    ->orWhere('phone', 'like', "%{$search}%")
                    ->orWhere('name', 'like', "%{$search}%")
                    ->orWhere('file_name', 'like', "%{$search}%");
            });
        }

        // Apply status filter
        if ($request->has('status') && $request->status !== 'all') {
            $query->where('status', $request->status);
        }

        // Apply source filter
        if ($request->has('source') && $request->source !== 'all') {
            $query->where('source', $request->source);
        }

        // Apply category filter
        if ($request->has('category') && $request->category !== 'all') {
            $query->where('category', $request->category);
        }

        // Use explicit orderBy with index for better performance
        // This prevents MySQL from sorting all rows before pagination
        $leads = $query->orderBy('created_at', 'desc')
                      ->paginate($request->get('per_page', 15));

        return response()->json($leads);
    }

    /**
     * Store a newly created lead (File Upload).
     */
    public function store(Request $request)
    {
        $user = $request->user();
        
        // Determine company_id (can be null)
        if ($user->isSuperAdmin() && $request->has('company_id')) {
            $companyId = $request->company_id;
        } else {
            $companyId = $user->company_id ?? null;
        }

        // Validate company_id if provided (for super admin)
        if ($user->isSuperAdmin() && $request->has('company_id') && !empty($request->company_id)) {
            $request->validate([
                'company_id' => 'exists:companies,id',
            ]);
        }

        $request->validate([
            'file' => 'required|file|mimes:csv,txt,xlsx,xls',
            'category' => 'required|string|max:255',
            'format' => 'required|string|in:csv,excel',
        ]);

        $file = $request->file('file');
        $fileName = $file->getClientOriginalName();
        $format = $request->input('format');
        $category = $request->input('category');

        try {
            $data = $this->parseFile($file, $format);
            
            if (empty($data['headers']) || empty($data['records'])) {
                return response()->json(['message' => 'File is empty or invalid format'], 400);
            }

            // Create a single lead record representing this file upload
            // The actual data is stored in the JSON columns
            $lead = Lead::create([
                'company_id' => $companyId,
                'name' => $fileName, // Use filename as the lead name for now
                'category' => $category,
                'status' => 'cold', // Default status
                'file_name' => $fileName,
                'file_format' => $format,
                'file_headers' => $data['headers'],
                'file_records' => $data['records'],
                'assigned_to' => $user->id, // Assign to uploader initially
            ]);

            return response()->json($lead, 201);

        } catch (\Exception $e) {
            Log::error('Lead file upload failed', ['error' => $e->getMessage()]);
            return response()->json(['message' => 'Failed to process file: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Convert string to UTF-8 encoding, handling malformed characters.
     */
    private function convertToUtf8($string)
    {
        if (!is_string($string)) {
            return $string;
        }

        // Remove BOM if present
        $string = str_replace("\xEF\xBB\xBF", '', $string);
        
        // Check if already valid UTF-8
        if (mb_check_encoding($string, 'UTF-8')) {
            return trim($string);
        }

        // Try to detect encoding and convert
        $detectedEncoding = mb_detect_encoding($string, ['UTF-8', 'ISO-8859-1', 'Windows-1252', 'ASCII'], true);
        
        if ($detectedEncoding && $detectedEncoding !== 'UTF-8') {
            $converted = mb_convert_encoding($string, 'UTF-8', $detectedEncoding);
            // If conversion failed, use iconv as fallback
            if ($converted === false) {
                $converted = @iconv($detectedEncoding, 'UTF-8//IGNORE', $string);
            }
            return trim($converted !== false ? $converted : $string);
        }

        // If detection failed, try to clean invalid UTF-8 characters
        return trim(mb_convert_encoding($string, 'UTF-8', 'UTF-8'));
    }

    /**
     * Parse the uploaded file based on format.
     */
    private function parseFile($file, $format)
    {
        $headers = [];
        $records = [];

        if ($format === 'csv') {
            $path = $file->getRealPath();
            $handle = fopen($path, 'r');
            if ($handle !== false) {
                // Get headers (first row)
                $headers = fgetcsv($handle);
                if ($headers === false) {
                    fclose($handle);
                    throw new \Exception('Unable to read headers from CSV file');
                }
                
                // Clean headers - remove BOM, convert to UTF-8, and trim whitespace
                $headers = array_map(function($header) {
                    return $this->convertToUtf8($header);
                }, $headers);
                
                // Get records
                while (($row = fgetcsv($handle)) !== false) {
                    // Only add if row has data
                    if (array_filter($row)) {
                        // Convert each cell to UTF-8
                        $row = array_map(function($cell) {
                            return $this->convertToUtf8($cell);
                        }, $row);
                        
                        // Ensure row has same number of columns as headers (pad or trim)
                        while (count($row) < count($headers)) {
                            $row[] = '';
                        }
                        if (count($row) > count($headers)) {
                            $row = array_slice($row, 0, count($headers));
                        }
                        $records[] = $row;
                    }
                }
                fclose($handle);
            } else {
                throw new \Exception('Unable to open CSV file');
            }
        } else {
            // Excel format using PhpSpreadsheet
            try {
                $spreadsheet = IOFactory::load($file->getRealPath());
                $worksheet = $spreadsheet->getActiveSheet();
                $highestRow = $worksheet->getHighestRow();
                $highestColumn = $worksheet->getHighestColumn();
                $highestColumnIndex = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($highestColumn);

                if ($highestRow < 1) {
                    throw new \Exception('Excel file is empty');
                }

                // Get headers (first row)
                $headers = [];
                for ($col = 1; $col <= $highestColumnIndex; $col++) {
                    $columnLetter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($col);
                    $cell = $worksheet->getCell($columnLetter . '1');
                    
                    // Get calculated value (handles formulas automatically)
                    $cellValue = $cell->getCalculatedValue();
                    
                    // Handle RichText
                    if ($cellValue instanceof \PhpOffice\PhpSpreadsheet\RichText\RichText) {
                        $cellValue = $cellValue->getPlainText();
                    }
                    
                    $headers[] = $this->convertToUtf8($cellValue ?? '');
                }

                // Remove empty trailing headers
                while (!empty($headers) && empty(end($headers))) {
                    array_pop($headers);
                }

                if (empty($headers)) {
                    throw new \Exception('No headers found in Excel file');
                }

                // Get records (starting from row 2)
                for ($row = 2; $row <= $highestRow; $row++) {
                    $record = [];
                    for ($col = 1; $col <= count($headers); $col++) {
                        $columnLetter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($col);
                        $cell = $worksheet->getCell($columnLetter . $row);
                        
                        // Get calculated value (handles formulas automatically)
                        $cellValue = $cell->getCalculatedValue();
                        
                        // Handle RichText
                        if ($cellValue instanceof \PhpOffice\PhpSpreadsheet\RichText\RichText) {
                            $cellValue = $cellValue->getPlainText();
                        }
                        
                        $record[] = $this->convertToUtf8($cellValue ?? '');
                    }
                    
                    // Only add if row has at least one non-empty value
                    if (array_filter($record)) {
                        $records[] = $record;
                    }
                }
            } catch (\PhpOffice\PhpSpreadsheet\Exception $e) {
                throw new \Exception('Failed to parse Excel file: ' . $e->getMessage());
            }
        }

        if (empty($headers)) {
            throw new \Exception('No headers found in file');
        }

        return [
            'headers' => $headers,
            'records' => $records
        ];
    }

    /**
     * Display the specified lead.
     */
    public function show(Request $request, Lead $lead)
    {
        $user = $request->user();

        // Check access
        if (!$user->isSuperAdmin() && $lead->company_id !== $user->company_id) {
            abort(403, 'Access denied');
        }

        return response()->json($lead);
    }

    /**
     * Update the specified lead.
     */
    public function update(Request $request, Lead $lead)
    {
        $user = $request->user();

        // Check access
        if (!$user->isSuperAdmin() && $lead->company_id !== $user->company_id) {
            abort(403, 'Access denied');
        }

        $validated = $request->validate([
            'status' => 'sometimes|string',
            'category' => 'sometimes|string',
            'assigned_to' => 'sometimes|exists:users,id',
        ]);

        $lead->update($validated);

        return response()->json($lead);
    }

    /**
     * Remove the specified lead.
     */
    public function destroy(Request $request, Lead $lead)
    {
        $user = $request->user();

        // Check access
        if (!$user->isSuperAdmin() && $lead->company_id !== $user->company_id) {
            abort(403, 'Access denied');
        }

        $lead->delete();

        return response()->json(['message' => 'Lead deleted successfully'], 204);
    }

    /**
     * Export leads to CSV
     */
    public function export(Request $request)
    {
        $user = $request->user();
        $companyId = $user->isSuperAdmin() && $request->has('company_id')
            ? $request->company_id
            : ($user->company_id ?? null);

        // Build query based on company_id (same as index method)
        if ($user->isSuperAdmin() && !$request->has('company_id')) {
            $query = Lead::query();
        } elseif ($companyId === null) {
            $query = Lead::whereNull('company_id');
        } else {
            $query = Lead::where('company_id', $companyId);
        }

        // Apply filters (same as index method)
        if ($request->has('search') && $request->search) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('email', 'like', "%{$search}%")
                    ->orWhere('phone', 'like', "%{$search}%")
                    ->orWhere('name', 'like', "%{$search}%")
                    ->orWhere('file_name', 'like', "%{$search}%");
            });
        }

        if ($request->has('status') && $request->status !== 'all') {
            $query->where('status', $request->status);
        }

        if ($request->has('source') && $request->source !== 'all') {
            $query->where('source', $request->source);
        }

        if ($request->has('category') && $request->category !== 'all') {
            $query->where('category', $request->category);
        }

        // Get all leads (no pagination for export)
        $leads = $query->orderBy('created_at', 'desc')->get();

        // Prepare CSV data
        $csvData = [];
        $headers = ['ID', 'Name', 'Email', 'Phone', 'Source', 'Status', 'Category', 'File Name', 'Assigned To', 'Value', 'Created At'];
        $csvData[] = $headers;

        foreach ($leads as $lead) {
            $row = [
                $lead->id,
                $lead->name ?? '',
                $lead->email ?? '',
                $lead->phone ?? '',
                $lead->source ?? '',
                $lead->status ?? '',
                $lead->category ?? '',
                $lead->file_name ?? '',
                $lead->assigned_to ?? '',
                $lead->value ?? '',
                $lead->created_at ? $lead->created_at->format('Y-m-d H:i:s') : '',
            ];
            $csvData[] = $row;
        }

        // Generate CSV content
        $filename = 'leads_export_' . date('Y-m-d_His') . '.csv';
        $handle = fopen('php://temp', 'r+');
        
        // Add BOM for UTF-8
        fwrite($handle, "\xEF\xBB\xBF");
        
        foreach ($csvData as $row) {
            fputcsv($handle, $row);
        }
        
        rewind($handle);
        $csvContent = stream_get_contents($handle);
        fclose($handle);

        return response($csvContent)
            ->header('Content-Type', 'text/csv; charset=UTF-8')
            ->header('Content-Disposition', 'attachment; filename="' . $filename . '"');
    }
}
