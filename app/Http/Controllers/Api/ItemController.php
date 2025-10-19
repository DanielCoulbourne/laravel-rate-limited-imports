<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Item;
use Illuminate\Http\Request;

class ItemController extends Controller
{
    /**
     * Display a paginated listing of items.
     */
    public function index(Request $request)
    {
        $perPage = $request->input('perPage', 15);

        // Limit perPage to a reasonable range (1-100)
        $perPage = max(1, min(100, (int) $perPage));

        $items = Item::paginate($perPage);

        return response()->json($items);
    }

    /**
     * Display the specified item.
     */
    public function show(Item $item)
    {
        return response()->json($item);
    }
}
