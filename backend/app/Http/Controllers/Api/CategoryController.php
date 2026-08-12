<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Category;
use Illuminate\Http\Request;

class CategoryController extends Controller
{
    public function index()
    {
        return response()->json(['success' => true, 'data' => Category::orderBy('CategoryID')->get()]);
    }

    public function store(Request $request)
    {
        $data = $request->validate(['category_name' => 'required|string|max:255', 'description' => 'nullable|string|max:255']);
        $cat = Category::create(['CategoryName' => $data['category_name'], 'Description' => $data['description'] ?? null]);
        return response()->json(['success' => true, 'message' => 'Category added.', 'data' => $cat]);
    }

    public function update(Request $request, int $id)
    {
        $cat = Category::find($id);
        if (!$cat) return response()->json(['success' => false, 'message' => 'Category not found.'], 404);
        $data = $request->validate(['category_name' => 'sometimes|string|max:255', 'description' => 'nullable|string|max:255']);
        $cat->fill(array_filter(['CategoryName' => $data['category_name'] ?? null, 'Description' => $data['description'] ?? null], fn ($v) => $v !== null));
        $cat->save();
        return response()->json(['success' => true, 'message' => 'Category updated.', 'data' => $cat]);
    }

    public function destroy(int $id)
    {
        $cat = Category::find($id);
        if (!$cat) return response()->json(['success' => false, 'message' => 'Category not found.'], 404);
        $cat->delete();
        return response()->json(['success' => true, 'message' => 'Category deleted.']);
    }
}
