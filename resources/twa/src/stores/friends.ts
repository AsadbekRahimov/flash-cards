import { defineStore } from 'pinia'
import { api } from '@/api'
import { errorMessage } from '@/i18n/errors'
import type { ChallengeMetric, ChallengesResponse, FriendsResponse } from '@/api/types'

export type FriendsStatus = 'idle' | 'loading' | 'ready' | 'error'

interface FriendsState {
  friends: FriendsResponse | null
  challenges: ChallengesResponse | null
  status: FriendsStatus
  errorMessage: string
  /** The failure of the last action (accept, decline, remove, challenge…), shown above the lists. */
  actionError: string
  /** Ids of the friendships / challenges an action is running for: their buttons are disabled. */
  busy: string[]
}

/** Friends and challenges (docs/13 §7.5, docs/04 A.13). Every friends action answers with the refreshed list. */
export const useFriendsStore = defineStore('friends', {
  state: (): FriendsState => ({
    friends: null,
    challenges: null,
    status: 'idle',
    errorMessage: '',
    actionError: '',
    busy: [],
  }),

  actions: {
    isBusy(key: string): boolean {
      return this.busy.includes(key)
    },

    async load(): Promise<void> {
      this.status = this.friends === null ? 'loading' : this.status
      this.errorMessage = ''
      try {
        const [friends, challenges] = await Promise.all([api.friends.get(), api.challenges.list()])
        this.friends = friends
        this.challenges = challenges
        this.status = 'ready'
      } catch (err) {
        this.status = 'error'
        this.errorMessage = errorMessage(err, 'settings')
      }
    },

    async refreshChallenges(): Promise<void> {
      try {
        this.challenges = await api.challenges.list()
      } catch {
        /* the lists reload on the next open; the action itself has succeeded */
      }
    },

    async friendAction(kind: 'accept' | 'decline' | 'remove', id: number): Promise<boolean> {
      return this.run(`f${id}`, async () => {
        this.friends = await api.friends[kind](id)
        // Removing a friend cancels the open challenge with them.
        if (kind === 'remove') await this.refreshChallenges()
      })
    },

    async propose(friendshipId: number, metric: ChallengeMetric): Promise<boolean> {
      return this.run(`f${friendshipId}`, async () => {
        await api.challenges.propose(friendshipId, metric)
        await this.refreshChallenges()
      })
    },

    async answerChallenge(kind: 'accept' | 'decline', id: number): Promise<boolean> {
      return this.run(`c${id}`, async () => {
        await api.challenges[kind](id)
        await this.refreshChallenges()
      })
    },

    /** Runs one action: busy while it runs, its error (localized) in `actionError`; true on success. */
    async run(key: string, action: () => Promise<void>): Promise<boolean> {
      if (this.busy.includes(key)) return false
      this.busy.push(key)
      this.actionError = ''
      try {
        await action()
        return true
      } catch (err) {
        this.actionError = errorMessage(err, 'settings')
        // The row may be gone (the other side acted first): show what is true now.
        await this.load()
        return false
      } finally {
        this.busy = this.busy.filter((k) => k !== key)
      }
    },
  },
})
