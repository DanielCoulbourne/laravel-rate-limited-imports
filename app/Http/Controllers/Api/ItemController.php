<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ApiItem;
use Illuminate\Http\Request;

class ItemController extends Controller
{
    /**
     * Display a paginated listing of API items.
     *
     * Serves data from the api_items table (source data).
     * This is separate from imported_items which contains the imported data.
     *
     * IMPORTANT: Only serve original seeded items (without external_id).
     * This prevents a feedback loop where imported items (with external_id)
     * get re-imported during pagination.
     */
    public function index(Request $request)
    {
        $perPage = $request->input('perPage', 15);


        // Limit perPage to a reasonable range (1-100)
        $perPage = max(1, min(100, (int) $perPage));

        $items = ApiItem::paginate($perPage);



        return response()->json($items);
    }

    /**
     * Display the specified API item.
     */
    public function show(ApiItem $item)
    {
        return response()->json($item);
    }
}
