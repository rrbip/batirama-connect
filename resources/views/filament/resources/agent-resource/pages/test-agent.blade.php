<x-filament-panels::page>
    @php
        $agent = $this->getRecord();
        $testSession = $this->getTestSession();
    @endphp

    <div class="grid grid-cols-1 lg:grid-cols-4 gap-6">
        {{-- Info Agent --}}
        <div class="lg:col-span-1">
            <x-filament::section>
                <x-slot name="heading">
                    <div class="flex items-center gap-2">
                        <x-heroicon-o-cpu-chip class="w-5 h-5" />
                        {{ $agent->name }}
                    </div>
                </x-slot>

                <div class="space-y-3 text-sm">
                    @foreach($this->agentInfo as $label => $value)
                        <div class="flex justify-between">
                            <span class="text-gray-500 dark:text-gray-400">{{ $label }}</span>
                            <span class="font-medium">{{ $value }}</span>
                        </div>
                    @endforeach
                </div>

                @if($agent->description)
                    <div class="mt-4 pt-4 border-t border-gray-200 dark:border-gray-700">
                        <p class="text-sm text-gray-600 dark:text-gray-400">
                            {{ $agent->description }}
                        </p>
                    </div>
                @endif

                @if($testSession)
                    <div class="mt-4 pt-4 border-t border-gray-200 dark:border-gray-700">
                        <div class="text-xs text-gray-500">
                            Session: {{ Str::limit($testSession->id, 8) }}
                        </div>
                    </div>
                @endif
            </x-filament::section>
        </div>

        {{-- Zone de chat --}}
        <div class="lg:col-span-3">
            <x-filament::section class="h-full">
                <x-slot name="heading">
                    Console de test
                </x-slot>

                {{-- Messages --}}
                <div class="h-96 overflow-y-auto mb-4 space-y-4 p-4 bg-gray-50 dark:bg-gray-900 rounded-lg" id="chat-messages">
                    @forelse($messages as $message)
                        <div class="flex {{ $message['role'] === 'user' ? 'justify-end' : 'justify-start' }}">
                            <div class="max-w-[80%] {{ $message['role'] === 'user'
                                ? 'bg-primary-500 text-white'
                                : ($message['role'] === 'error'
                                    ? 'bg-danger-100 text-danger-800 dark:bg-danger-900 dark:text-danger-200'
                                    : 'bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700')
                            }} rounded-lg p-3 shadow-sm">
                                <div class="prose prose-sm dark:prose-invert max-w-none">
                                    {!! \Illuminate\Support\Str::markdown($message['content']) !!}
                                </div>
                                <div class="flex items-center justify-between mt-2 text-xs {{ $message['role'] === 'user' ? 'text-primary-200' : 'text-gray-400' }}">
                                    <span>{{ $message['timestamp'] }}</span>
                                    @if(isset($message['tokens']))
                                        <span>{{ $message['tokens'] }} tokens</span>
                                    @endif
                                </div>
                                @if(!empty($message['sources']))
                                    <div class="mt-2 pt-2 border-t border-gray-200 dark:border-gray-700">
                                        <span class="text-xs text-gray-500">Sources:</span>
                                        <ul class="text-xs text-gray-500 mt-1">
                                            @foreach($message['sources'] as $source)
                                                <li>• {{ $source['title'] ?? $source['id'] ?? 'Document' }}</li>
                                            @endforeach
                                        </ul>
                                    </div>
                                @endif
                            </div>
                        </div>
                    @empty
                        <div class="flex items-center justify-center h-full text-gray-400">
                            <div class="text-center">
                                <x-heroicon-o-chat-bubble-left-ellipsis class="w-12 h-12 mx-auto mb-2" />
                                <p>Envoyez un message pour commencer</p>
                            </div>
                        </div>
                    @endforelse

                    @if($isLoading)
                        <div class="flex justify-start">
                            <div class="bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-lg p-3 shadow-sm">
                                <div class="flex items-center gap-2">
                                    <x-filament::loading-indicator class="h-5 w-5" />
                                    <span class="text-sm text-gray-500">L'agent réfléchit...</span>
                                </div>
                            </div>
                        </div>
                    @endif
                </div>

                {{-- Input --}}
                <form wire:submit="sendMessage" class="flex gap-2">
                    <div class="flex-1">
                        <x-filament::input.wrapper>
                            <x-filament::input
                                type="text"
                                wire:model="userMessage"
                                placeholder="Tapez votre message..."
                                :disabled="$isLoading"
                                autofocus
                            />
                        </x-filament::input.wrapper>
                    </div>
                    <x-filament::button
                        type="submit"
                        :disabled="$isLoading"
                        icon="heroicon-o-paper-airplane"
                    >
                        Envoyer
                    </x-filament::button>
                </form>
            </x-filament::section>
        </div>
    </div>

    @push('scripts')
    <script>
        // Auto-scroll to bottom on new messages
        document.addEventListener('livewire:updated', () => {
            const container = document.getElementById('chat-messages');
            if (container) {
                container.scrollTop = container.scrollHeight;
            }
        });
    </script>
    @endpush
</x-filament-panels::page>
