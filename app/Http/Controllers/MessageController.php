<?php

namespace App\Http\Controllers;

use App\Models\Message;
use App\Models\Conversation;
use App\Services\ChatService;
use Illuminate\Http\Request;
use Inertia\Inertia;

class MessageController extends Controller
{
    protected $chatService;

    public function __construct(ChatService $chatService)
    {
        $this->chatService = $chatService;
    }

    /**
     * Display a listing of the resource.
     */
    public function index($conversationId)
    {
        $messages = Message::where('conversation_id', $conversationId)
            ->orderBy('created_at', 'asc')
            ->get();

        return response()->json(['messages' => $messages]);
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        //
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request, $conversationId)
    {
        $conversation = Conversation::findOrFail($conversationId);

        // Sauvegarder le message utilisateur
        Message::create([
            'conversation_id' => $conversationId,
            'role' => 'user',
            'content' => $request->message
        ]);

        // Préparer le fil des messages
        $messages = $conversation->messages()
            ->orderBy('created_at', 'asc')
            ->get()
            ->map(fn($msg) => ['role' => $msg->role, 'content' => $msg->content])
            ->toArray();

        // Obtenir la réponse de l'IA
        $aiResponse = $this->chatService->sendMessage(
            messages: $messages,
            model: $request->model ?? $conversation->model
        );

        // Sauvegarder la réponse
        Message::create([
            'conversation_id' => $conversationId,
            'role' => 'assistant',
            'content' => $aiResponse
        ]);

        // Gestion améliorée du titre
        $shouldGenerateTitle = $conversation->title === 'Nouvelle conversation' ||
                              ($conversation->messages()->count() % 7 === 0); // Régénérer tous les 7 messages

        if ($shouldGenerateTitle) {
            try {
                // Demander à l'IA de générer un titre basé sur les derniers messages
                $contextMessages = $conversation->messages()
                    ->orderBy('created_at', 'desc')
                    ->take(3)
                    ->get()
                    ->map(fn($msg) => $msg->content)
                    ->join("\n");

                $titlePrompt = [
                    [
                        'role' => 'system',
                        'content' => 'Génère un titre court et concis (maximum 50 caractères) qui résume cette conversation. Donne uniquement le titre, sans guillemets ni ponctuation.'
                    ],
                    [
                        'role' => 'user',
                        'content' => $contextMessages
                    ]
                ];

                $title = $this->chatService->sendMessage(
                    messages: $titlePrompt,
                    model: $request->model ?? $conversation->model
                );

                // Nettoyer et limiter le titre
                $title = \Str::limit(trim($title), 50);

                $conversation->update([
                    'title' => $title,
                    'last_activity' => now()
                ]);
            } catch (\Exception $e) {
                logger()->error('Erreur lors de la mise à jour du titre:', [
                    'error' => $e->getMessage(),
                    'conversation_id' => $conversationId
                ]);
            }
        } else {
            $conversation->update(['last_activity' => now()]);
        }

        // Recharger la conversation avec ses relations pour refléter le nouveau titre
        $conversation = $conversation->fresh();

        // Mettre à jour last_activity avec la date actuelle
        $conversation->update([
            'last_activity' => now(),
        ]);

        // Récupérer toutes les conversations triées par last_activity
        $conversations = Conversation::where('user_id', auth()->id())
            ->orderBy('last_activity', 'desc')
            ->get();

        return response()->json([
            'messages' => $conversation->messages()->orderBy('created_at', 'asc')->get(),
            'conversation' => $conversation,
            'conversations' => $conversations
        ]);
    }

    /**
     * Display the specified resource.
     */
    public function show(Message $message)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(Message $message)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Message $message)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Message $message)
    {
        //
    }
}
