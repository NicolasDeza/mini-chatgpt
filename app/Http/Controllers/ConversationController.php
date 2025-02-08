<?php
namespace App\Http\Controllers;
use App\Services\ChatService;
use App\Models\Conversation;
use Illuminate\Http\Request;
use Inertia\Inertia;

class ConversationController extends Controller
{
    private ChatService $chatService;

    public function __construct(ChatService $chatService)
    {
        $this->chatService = $chatService;
    }

    public function index()
    {
        $conversations = Conversation::where('user_id', auth()->id())
            ->orderBy('updated_at', 'desc')
            ->get();

        return Inertia::render('Ask/Index', [
            'conversations' => $conversations,
            'models' => $this->chatService->getModels(),
            'selectedModel' => $this->chatService::DEFAULT_MODEL,
            'messages' => [],
        ]);
    }

    public function store(Request $request)
    {
        $conversation = Conversation::create([
            'user_id' => auth()->id(),
            'model' => $this->chatService::DEFAULT_MODEL,
            'is_temporary' => false,
            'title' => 'Nouvelle conversation', // Titre par défaut
            'last_activity' => now(),
            'context' => json_encode([]),
        ]);

        return response()->json([
            'conversation' => $conversation,
            'conversations' => Conversation::where('user_id', auth()->id())
                ->orderBy('updated_at', 'desc')
                ->get()
        ]);
    }

    public function show(Conversation $conversation)
    {
        if ($conversation->user_id !== auth()->id()) {
            abort(403);
        }

        return response()->json([
            'conversation' => $conversation,
            'messages' => json_decode($conversation->context, true) ?? [],
        ]);
    }

    // Ajout d'une méthode pour mettre à jour le titre
    public function updateTitle(Request $request, Conversation $conversation)
    {
        if ($conversation->user_id !== auth()->id()) {
            abort(403);
        }

        $conversation->update([
            'title' => $request->title
        ]);

        return response()->json(['conversation' => $conversation]);
    }
}
