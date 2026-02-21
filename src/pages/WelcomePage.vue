<script setup lang="ts">
import { postJson } from '@/api/client';
import { getSurveyEnv } from '@/utils/surveyEnv';
import { getSurveySource } from '@/utils/surveySource';
import { POPUP_VERSION } from '@/utils/popupVersion';

const SESSION_KEY = 'cp_survey_session';
const CTA_KEY = 'cp_survey_cta';

const getSessionId = () => {
  try {
    const existing = sessionStorage.getItem(SESSION_KEY);
    if (existing) return existing;
    const generated =
      typeof crypto !== 'undefined' && 'randomUUID' in crypto
        ? crypto.randomUUID()
        : `s_${Date.now()}_${Math.random().toString(16).slice(2)}`;
    sessionStorage.setItem(SESSION_KEY, generated);
    return generated;
  } catch {
    return `s_${Date.now()}_${Math.random().toString(16).slice(2)}`;
  }
};

const postFeedback = async (payload: Record<string, unknown>) => {
  try {
    const response = await postJson('/api/feedback.php', payload);
    return response.ok;
  } catch {
    return false;
  }
};

const onCtaClick = () => {
  try {
    sessionStorage.setItem(CTA_KEY, 'true');
  } catch {}
  void postFeedback({
    action: 'cta',
    session_id: getSessionId(),
    cta_clicked: 1,
    source: getSurveySource(),
    env: getSurveyEnv(),
    popup_version: POPUP_VERSION
  });
};
</script>

<template>
  <section class="screen welcome-screen" aria-label="Welcome">
    <div class="welcome-screen__content">
      <div class="welcome-screen__title">
        <div class="welcome-screen__title-cn">
          <p class="welcome-screen__line">机灵</p>
          <p class="welcome-screen__line">小熊猫</p>
        </div>
        <div class="welcome-screen__title-en">
          <p class="welcome-screen__line">CLEVER LITTLE</p>
          <p class="welcome-screen__line">PANDA</p>
        </div>
      </div>
      <div class="welcome-screen__copy">
        <p class="welcome-screen__copy-line">在阅读双语小故事的过程中学习英语</p>
        <div class="welcome-screen__copy-block">
          <p class="welcome-screen__copy-line">如果您的孩子刚好是在7岁到11岁之间</p>
          <p class="welcome-screen__copy-line">请和孩子一起大声朗读，按下单词的键</p>
          <p class="welcome-screen__copy-line">听听每个单词的发音</p>
        </div>
        <div class="welcome-screen__copy-block">
          <p class="welcome-screen__copy-line">试用时无需注册</p>
          <p class="welcome-screen__copy-line">试用仅需2-3分钟</p>
          <p class="welcome-screen__copy-line">可以随时停止</p>
        </div>
      </div>
      <button class="welcome-screen__cta" type="button" @click="onCtaClick">
        <span class="welcome-screen__cta-line">点击这里先看一下</span>
        <!-- <span class="welcome-screen__cta-line">可先试用1至2页看看</span> -->
      </button>
    </div>
  </section>
</template>

<style scoped lang="less">
.welcome-screen {
  background: #a6c92a;
  color: #f8f6e6;
  justify-content: flex-start;
}

.welcome-screen__content {
  display: flex;
  flex-direction: column;
  align-items: center;
  width: min(86vw, 320px);
  margin-top: 12vh;
  text-align: center;
}

.welcome-screen__title {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 8px;
}

.welcome-screen__title-cn {
  font-size: 50px;
  font-weight: 700;
  line-height: 1.12;
  font-family: "FZCuYuan", "Gotham Rounded";
  .welcome-screen__line:last-of-type {
    font-size: 32px;
  }
}

.welcome-screen__title-en {
  font-size: 18px;
  font-weight: 700;
  font-family: "Gotham Rounded", "FZCuYuan";
  color: #f2f7b8;
  .welcome-screen__line {
    line-height: 1;
  }
}

.welcome-screen__line {
  margin: 0;
}

.welcome-screen__copy {
  display: flex;
  flex-direction: column;
  gap: 14px;
  color: #FAFF98;
  font-size: 13px;
  line-height: 21px;
  letter-spacing: 1px;
  font-family: "FZCuYuan", "Gotham Rounded";
  margin-top: 30px;
  height: 210px;
}

.welcome-screen__copy-block {
  display: flex;
  flex-direction: column;
  gap: 2px;
}

.welcome-screen__copy-line {
  margin: 0;
}

.welcome-screen__cta {
  margin-top: 12px;
  padding: 7px 26px;
  border-radius: 999px;
  border: 3px solid #f8f6e6;
  color: #FAFF98;
  font-weight: 400;
  line-height: 21px;
  font-size: 16px;
  font-family: "FZCuYuan", "Gotham Rounded";
  background: transparent;
  cursor: pointer;
}

.welcome-screen__cta-line {
  margin: 0;
  display: block;
}
</style>
