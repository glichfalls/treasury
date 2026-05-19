import { ref } from 'vue'

const LS_KEY = 'treasury_privacy_mode'
const hideBalances = ref(localStorage.getItem(LS_KEY) === 'true')

function apply(v: boolean) {
  document.body.classList.toggle('privacy-mode', v)
  localStorage.setItem(LS_KEY, v ? 'true' : 'false')
}

apply(hideBalances.value)

export function usePrivacyMode() {
  function toggle() {
    hideBalances.value = !hideBalances.value
    apply(hideBalances.value)
  }
  return { hideBalances, toggle }
}
