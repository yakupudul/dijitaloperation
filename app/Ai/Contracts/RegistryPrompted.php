<?php

namespace App\Ai\Contracts;

/**
 * An agent whose instructions come from the PromptRegistry (Faz 8). The usage recorder links each run to the prompt
 * version the agent used.
 */
interface RegistryPrompted
{
    /** The AI operation key (= AI route key) of this agent's prompt. */
    public function promptOperation(): string;

    public function promptVersionId(): ?int;
}
