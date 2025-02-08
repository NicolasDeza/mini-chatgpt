<script setup>
import { ref, watch, nextTick, computed } from "vue";
import { useForm, usePage, Link, router } from "@inertiajs/vue3";
import MarkdownIt from "markdown-it";
import hljs from "highlight.js";
import "highlight.js/styles/github-dark.css";

// ✅ Récupération des props passées par Inertia
const page = usePage();
const models = computed(() => page.props.models || []);
const selectedModel = ref(page.props.selectedModel);
const flashMessage = ref(page.props.flash?.message || "");
const flashError = ref(page.props.flash?.error || "");
const loading = ref(false);
const conversations = ref(page.props.conversations || []);
const selectedConversation = ref(page.props.selectedConversation || null);
const messages = ref(page.props.messages || []);
const currentConversationId = ref(null);
const editingTitle = ref(null);
const newTitle = ref("");

console.log(" Models chargés :", models.value);
console.log(" Conversations chargées :", conversations.value);
console.log("Messages chargés :", messages.value);

// ✅ Initialisation de Markdown avec Highlight.js
const md = new MarkdownIt({
    highlight: function (str, lang) {
        if (lang && hljs.getLanguage(lang)) {
            try {
                return `<pre class="hljs"><code>${
                    hljs.highlight(str, { language: lang }).value
                }</code></pre>`;
            } catch (__) {}
        }
        return `<pre class="hljs"><code>${md.utils.escapeHtml(
            str
        )}</code></pre>`;
    },
});

// ✅ Sélectionner une conversation
const selectConversation = (conversation) => {
    if (currentConversationId.value === conversation.id) return;

    loading.value = true;
    currentConversationId.value = conversation.id;
    selectedConversation.value = conversation;

    axios
        .get(route("chat.show", conversation.id))
        .then((response) => {
            messages.value = response.data.messages;
            scrollToBottom();
        })
        .catch((error) => {
            console.error("Erreur lors du chargement des messages:", error);
        })
        .finally(() => {
            loading.value = false;
        });
};

// Ajouter cette fonction pour gérer les titres
const updateConversationTitle = (conversation) => {
    if (!newTitle.value.trim()) {
        newTitle.value = conversation.title;
        editingTitle.value = null;
        return;
    }

    axios
        .post(route("chat.updateTitle", conversation.id), {
            title: newTitle.value,
        })
        .then((response) => {
            const updatedConv = response.data.conversation;
            const index = conversations.value.findIndex(
                (c) => c.id === updatedConv.id
            );
            if (index !== -1) {
                conversations.value[index] = updatedConv;
            }
            editingTitle.value = null;
        })
        .catch((error) => {
            console.error("Erreur lors de la mise à jour du titre:", error);
        });
};

const createConversation = () => {
    loading.value = true;

    axios
        .post(route("chat.store"))
        .then((response) => {
            conversations.value = response.data.conversations;
            selectConversation(response.data.conversation);
            messages.value = [];
        })
        .catch((error) => {
            console.error(
                "Erreur lors de la création de la conversation:",
                error
            );
        })
        .finally(() => {
            loading.value = false;
        });
};

// ✅ Scroll automatique du chat
const scrollToBottom = () => {
    nextTick(() => {
        const chatContainer = document.querySelector(".chat-container");
        if (chatContainer) {
            chatContainer.scrollTop = chatContainer.scrollHeight;
        }
    });
};

// ✅ Formulaire d'envoi de message
const form = useForm({
    message: "",
    model: selectedModel.value,
    conversation_id: null,
});

// ✅ Envoi du message à l'IA
const sendMessage = () => {
    if (!form.message.trim()) return;

    form.conversation_id = selectedConversation.value?.id;

    messages.value.push({ role: "user", content: form.message });
    messages.value.push({ role: "assistant", content: "L'IA réfléchit..." });

    loading.value = true;

    form.post(route("ask.post"), {
        preserveScroll: true,
        preserveState: true,
        onSuccess: () => {
            messages.value = messages.value.filter(
                (msg) => msg.content !== "L'IA réfléchit..."
            );

            if (page.props.flash?.message) {
                messages.value.push({
                    role: "assistant",
                    content: page.props.flash.message,
                });
            }

            flashMessage.value = page.props.flash?.message || "";
            flashError.value = page.props.flash?.error || "";
            form.reset("message");
            scrollToBottom();
        },
        onError: () => {
            messages.value = messages.value.filter(
                (msg) => msg.content !== "L'IA réfléchit..."
            );
            flashError.value = "Une erreur est survenue lors de l'envoi.";
        },
        onFinish: () => {
            loading.value = false;
        },
    });
};

// ✅ Observer les messages pour le scroll automatique
watch(
    messages,
    () => {
        scrollToBottom();
    },
    { deep: true }
);

// ✅ Observer le modèle sélectionné
watch(selectedModel, (newModel) => {
    form.model = newModel;
});
</script>

<template>
    <div class="h-screen w-screen flex bg-gray-900 text-white">
        <!-- Sidebar -->
        <aside
            class="w-1/4 bg-gray-800 p-4 overflow-y-auto border-r border-gray-700"
        >
            <h2 class="text-xl font-bold mb-4">Conversations</h2>

            <button
                @click="createConversation"
                class="w-full p-2 mb-4 bg-blue-500 text-white rounded hover:bg-blue-600 transition flex items-center justify-center"
                :disabled="loading"
            >
                <span v-if="!loading">+ Nouvelle conversation</span>
                <span v-else>Chargement...</span>
            </button>

            <div class="space-y-2">
                <div
                    v-for="conversation in conversations"
                    :key="conversation.id"
                    class="cursor-pointer p-3 rounded-lg transition-colors duration-200"
                    :class="{
                        'bg-gray-700':
                            selectedConversation?.id === conversation.id,
                        'hover:bg-gray-700':
                            selectedConversation?.id !== conversation.id,
                    }"
                >
                    <div
                        class="flex justify-between items-center"
                        @click="selectConversation(conversation)"
                    >
                        <div v-if="editingTitle === conversation.id">
                            <input
                                v-model="newTitle"
                                @blur="updateConversationTitle(conversation)"
                                @keyup.enter="
                                    updateConversationTitle(conversation)
                                "
                                class="bg-gray-600 text-white px-2 py-1 rounded"
                                ref="titleInput"
                            />
                        </div>
                        <div
                            v-else
                            class="font-medium"
                            @dblclick="
                                () => {
                                    editingTitle = conversation.id;
                                    newTitle = conversation.title;
                                    $nextTick(() => {
                                        $refs.titleInput?.focus();
                                    });
                                }
                            "
                        >
                            {{ conversation.title || "Nouvelle conversation" }}
                        </div>
                        <div class="text-sm text-gray-400">
                            {{
                                new Date(
                                    conversation.last_activity
                                ).toLocaleDateString("fr-FR")
                            }}
                        </div>
                    </div>
                </div>
            </div>
        </aside>

        <!-- Zone principale -->
        <main class="flex-1 flex flex-col h-full">
            <div class="w-full h-full bg-gray-800 p-6 flex flex-col">
                <!-- Sélecteur de modèle -->
                <div class="mb-4">
                    <label class="block font-medium text-gray-200 mb-2"
                        >Modèle utilisé :</label
                    >
                    <select
                        v-model="form.model"
                        class="w-full p-2 border rounded bg-gray-700 text-white"
                    >
                        <option
                            v-for="model in models"
                            :key="model.id"
                            :value="model.id"
                        >
                            {{ model.name }}
                        </option>
                    </select>
                </div>

                <h1 class="text-2xl font-semibold text-center mb-4">
                    Que puis-je faire pour vous ?
                </h1>

                <!-- Messages -->
                <div
                    class="chat-container flex-1 bg-gray-700 p-4 rounded-lg overflow-y-auto border mb-4"
                >
                    <div
                        v-for="(msg, index) in messages"
                        :key="index"
                        class="mb-4 last:mb-0"
                    >
                        <div
                            class="flex"
                            :class="
                                msg.role === 'user'
                                    ? 'justify-end'
                                    : 'justify-start'
                            "
                        >
                            <div
                                class="max-w-[80%] p-3 rounded-lg shadow-md"
                                :class="
                                    msg.role === 'user'
                                        ? 'bg-blue-500'
                                        : 'bg-gray-600'
                                "
                            >
                                <strong
                                    >{{
                                        msg.role === "user"
                                            ? "Vous"
                                            : "Assistant"
                                    }}:</strong
                                >
                                <div
                                    class="mt-1 prose prose-invert max-w-none"
                                    v-html="md.render(msg.content)"
                                />
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Formulaire -->
                <form @submit.prevent="sendMessage" class="flex space-x-2">
                    <input
                        v-model="form.message"
                        type="text"
                        class="flex-1 p-3 border rounded-lg focus:ring focus:ring-blue-300 bg-gray-700 text-white"
                        placeholder="Posez votre question..."
                        :disabled="loading || !selectedConversation"
                    />
                    <button
                        type="submit"
                        class="bg-blue-500 text-white px-6 py-3 rounded-lg hover:bg-blue-600 transition"
                        :disabled="
                            loading ||
                            !selectedConversation ||
                            !form.message.trim()
                        "
                    >
                        {{ loading ? "Envoi..." : "Envoyer" }}
                    </button>
                </form>
            </div>
        </main>
    </div>
</template>
