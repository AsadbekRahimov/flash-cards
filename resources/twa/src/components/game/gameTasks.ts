import type { Component } from 'vue'
import ChoiceTask from '@/components/game/ChoiceTask.vue'
import GapTask from '@/components/game/GapTask.vue'
import PracticeTask from '@/components/game/PracticeTask.vue'
import SpellTask from '@/components/game/SpellTask.vue'
import type { GameElement } from '@/api/gameRunTypes'

/** Which component draws the element of a run: the three of the words, or the adapter to the practice. */
export function taskComponentFor(element: GameElement): Component {
  switch (element.kind) {
    case 'translate_fwd':
    case 'translate_back':
    case 'listen':
      return ChoiceTask
    case 'spell':
      return SpellTask
    case 'gap':
      return GapTask
    default:
      return PracticeTask
  }
}
