<script setup lang="ts">
import type { CSSProperties } from 'vue';

type Particle = {
  id: number;
  shape: 'dot' | 'ribbon';
  colorClass: string;
  sourceX: number;
  sourceY: number;
  txPeak: number;
  tyPeak: number;
  txEnd: number;
  tyEnd: number;
  rotPeak: number;
  rotEnd: number;
  delay: number;
  size: number;
};

const particles: Particle[] = [
  { id: 1, shape: 'dot', colorClass: 'color-1', sourceX: -56, sourceY: 6, txPeak: -180, tyPeak: -210, txEnd: -240, tyEnd: 180, rotPeak: -40, rotEnd: 120, delay: 0, size: 10 },
  { id: 2, shape: 'dot', colorClass: 'color-2', sourceX: -42, sourceY: -2, txPeak: -140, tyPeak: -236, txEnd: -196, tyEnd: 170, rotPeak: -25, rotEnd: 140, delay: 60, size: 8 },
  { id: 3, shape: 'ribbon', colorClass: 'color-3', sourceX: -28, sourceY: -8, txPeak: -90, tyPeak: -218, txEnd: -146, tyEnd: 176, rotPeak: -16, rotEnd: 180, delay: 90, size: 10 },
  { id: 4, shape: 'ribbon', colorClass: 'color-4', sourceX: -14, sourceY: -10, txPeak: -44, tyPeak: -248, txEnd: -84, tyEnd: 166, rotPeak: -6, rotEnd: 170, delay: 120, size: 8 },
  { id: 5, shape: 'dot', colorClass: 'color-5', sourceX: 0, sourceY: -12, txPeak: -16, tyPeak: -260, txEnd: -38, tyEnd: 184, rotPeak: 0, rotEnd: 160, delay: 150, size: 9 },
  { id: 6, shape: 'ribbon', colorClass: 'color-6', sourceX: 14, sourceY: -10, txPeak: 44, tyPeak: -246, txEnd: 84, tyEnd: 170, rotPeak: 6, rotEnd: 190, delay: 130, size: 8 },
  { id: 7, shape: 'dot', colorClass: 'color-7', sourceX: 28, sourceY: -8, txPeak: 90, tyPeak: -230, txEnd: 146, tyEnd: 176, rotPeak: 14, rotEnd: 220, delay: 110, size: 10 },
  { id: 8, shape: 'ribbon', colorClass: 'color-1', sourceX: 42, sourceY: -2, txPeak: 136, tyPeak: -238, txEnd: 196, tyEnd: 170, rotPeak: 24, rotEnd: 200, delay: 70, size: 10 },
  { id: 9, shape: 'dot', colorClass: 'color-2', sourceX: 56, sourceY: 6, txPeak: 180, tyPeak: -210, txEnd: 236, tyEnd: 180, rotPeak: 34, rotEnd: 180, delay: 50, size: 8 },
  { id: 10, shape: 'ribbon', colorClass: 'color-3', sourceX: -34, sourceY: 10, txPeak: -216, tyPeak: -170, txEnd: -266, tyEnd: 196, rotPeak: -52, rotEnd: 130, delay: 170, size: 8 },
  { id: 11, shape: 'dot', colorClass: 'color-4', sourceX: 34, sourceY: 10, txPeak: 214, tyPeak: -170, txEnd: 266, tyEnd: 196, rotPeak: 52, rotEnd: 230, delay: 180, size: 10 },
  { id: 12, shape: 'ribbon', colorClass: 'color-5', sourceX: 0, sourceY: 12, txPeak: 16, tyPeak: -220, txEnd: 46, tyEnd: 204, rotPeak: 20, rotEnd: 180, delay: 200, size: 10 }
];

const particleStyle = (particle: Particle): CSSProperties => ({
  '--sx': `${particle.sourceX}px`,
  '--sy': `${particle.sourceY}px`,
  '--tx-peak': `${particle.txPeak}px`,
  '--ty-peak': `${particle.tyPeak}px`,
  '--tx-end': `${particle.txEnd}px`,
  '--ty-end': `${particle.tyEnd}px`,
  '--rot-peak': `${particle.rotPeak}deg`,
  '--rot-end': `${particle.rotEnd}deg`,
  '--delay': `${particle.delay}ms`,
  '--size': `${particle.size}px`
});
</script>

<template>
  <div class="burst-overlay" aria-hidden="true">
    <span
      v-for="particle in particles"
      :key="particle.id"
      class="burst-overlay__particle"
      :class="[particle.shape === 'dot' ? 'is-dot' : 'is-ribbon', particle.colorClass]"
      :style="particleStyle(particle)"
    ></span>
  </div>
</template>

<style scoped lang="less">
.burst-overlay {
  position: fixed;
  inset: 0;
  overflow: hidden;
  pointer-events: none;
  z-index: 2;
}

.burst-overlay__particle {
  position: absolute;
  left: 50%;
  top: 32%;
  opacity: 0;
  animation-name: burstFullScreenFall;
  animation-duration: 2200ms;
  animation-timing-function: linear;
  animation-iteration-count: 1;
  animation-fill-mode: forwards;
  animation-delay: var(--delay, 0ms);
  transform-origin: center;
  background: var(--burst-color, #7fc92f);
}

.is-dot {
  width: var(--size);
  height: var(--size);
  border-radius: 999px;
}

.is-ribbon {
  width: calc(var(--size) * 2.2);
  height: calc(var(--size) * 0.86);
  border-radius: 999px;
}

.color-1 {
  --burst-color: #ff595e;
}

.color-2 {
  --burst-color: #ff924c;
}

.color-3 {
  --burst-color: #ffca3a;
}

.color-4 {
  --burst-color: #8ac926;
}

.color-5 {
  --burst-color: #00b7ff;
}

.color-6 {
  --burst-color: #4361ee;
}

.color-7 {
  --burst-color: #9d4edd;
}

@keyframes burstFullScreenFall {
  0% {
    opacity: 0;
    transform: translate(calc(-50% + var(--sx)), calc(-50% + var(--sy) + 8px)) rotate(0deg)
      scale(0.28);
  }
  5% {
    opacity: 1;
    transform: translate(calc(-50% + var(--sx)), calc(-50% + var(--sy))) rotate(0deg) scale(0.62);
  }
  18% {
    opacity: 1;
    transform: translate(calc(-50% + var(--sx) + var(--tx-peak)), calc(-50% + var(--sy) + var(--ty-peak)))
      rotate(var(--rot-peak)) scale(1);
  }
  55% {
    opacity: 0.95;
    transform: translate(
        calc(-50% + var(--sx) + var(--tx-peak) + (var(--tx-end) - var(--tx-peak)) * 0.45),
        calc(-50% + var(--sy) + var(--ty-peak) + (var(--ty-end) - var(--ty-peak)) * 0.45)
      )
      rotate(calc(var(--rot-peak) + (var(--rot-end) - var(--rot-peak)) * 0.45)) scale(0.92);
  }
  82% {
    opacity: 0.7;
    transform: translate(
        calc(-50% + var(--sx) + var(--tx-peak) + (var(--tx-end) - var(--tx-peak)) * 0.82),
        calc(-50% + var(--sy) + var(--ty-peak) + (var(--ty-end) - var(--ty-peak)) * 0.82)
      )
      rotate(calc(var(--rot-peak) + (var(--rot-end) - var(--rot-peak)) * 0.82)) scale(0.86);
  }
  100% {
    opacity: 0;
    transform: translate(calc(-50% + var(--sx) + var(--tx-end)), calc(-50% + var(--sy) + var(--ty-end)))
      rotate(var(--rot-end)) scale(0.8);
  }
}
</style>
