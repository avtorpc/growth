import { Component, DestroyRef, computed, inject, signal } from '@angular/core';
import { takeUntilDestroyed } from '@angular/core/rxjs-interop';
import { HttpErrorResponse } from '@angular/common/http';
import { finalize } from 'rxjs';
import { ImportResponse, OrderImportService, OrderStatus, Outcome } from './order-import';

const rubles = new Intl.NumberFormat('ru-RU', {
  style: 'currency', currency: 'RUB', minimumFractionDigits: 0, maximumFractionDigits: 2,
});

@Component({
  selector: 'app-root',
  standalone: true,
  templateUrl: './app.component.html',
})
export class AppComponent {
  private readonly importer = inject(OrderImportService);
  private readonly destroyRef = inject(DestroyRef);
  readonly loading = signal(false);
  readonly sending = signal(false);
  readonly resetting = signal(false);
  readonly resetConfirmation = signal(false);
  readonly resetMessage = signal('');
  readonly busy = computed(() => this.loading() || this.resetting());
  readonly error = signal('');
  readonly report = signal<ImportResponse | null>(null);
  readonly filter = signal<Outcome | 'all'>('all');
  readonly outcomes: Outcome[] = ['created', 'updated', 'unchanged', 'duplicate', 'rejected'];
  readonly labels: Record<Outcome, string> = {
    created: 'Создан', updated: 'Обновлён', unchanged: 'Без изменений',
    duplicate: 'Дубль', rejected: 'Отказ',
  };
  readonly summaryLabels: Record<Outcome, string> = {
    created: 'Создано', updated: 'Обновлено', unchanged: 'Без изменений',
    duplicate: 'Дубли', rejected: 'Отклонено',
  };
  readonly statuses: Record<OrderStatus, string> = {
    new: 'Новый', accepted: 'Принят', delivering: 'Доставляется',
    delivered: 'Доставлен', cancelled: 'Отменён',
  };
  readonly visibleResults = computed(() => {
    const filter = this.filter();
    return (this.report()?.results ?? []).filter(row => filter === 'all' || row.outcome === filter);
  });

  money(kopecks: number): string { return rubles.format(kopecks / 100); }

  setFilter(value: string): void {
    this.filter.set(this.outcomes.find(outcome => outcome === value) ?? 'all');
  }

  requestReset(): void {
    if (this.busy()) return;
    this.resetConfirmation.set(true);
    this.resetMessage.set('');
  }

  resetTestData(): void {
    if (this.busy() || !this.resetConfirmation()) return;
    this.resetting.set(true);
    this.error.set('');
    this.report.set(null);
    this.importer.resetTestData()
      .pipe(takeUntilDestroyed(this.destroyRef), finalize(() => this.resetting.set(false)))
      .subscribe({
        next: () => {
          this.resetConfirmation.set(false);
          this.filter.set('all');
          this.resetMessage.set('Тестовая база очищена. Можно загрузить заказы заново.');
        },
        error: (error: unknown) => {
          this.error.set(error instanceof HttpErrorResponse && error.status > 0
            ? error.error?.error?.message || 'Не удалось очистить базу. Повторите попытку.'
            : 'Ответ на очистку не получен. Данные могли быть удалены. Повторите очистку перед новой загрузкой.');
        },
      });
  }

  importOrders(): void {
    if (this.busy()) return;
    this.resetConfirmation.set(false);
    this.resetMessage.set('');
    this.loading.set(true);
    this.sending.set(false);
    this.error.set('');
    this.report.set(null);
    this.filter.set('all');
    this.importer.importOrders(() => this.sending.set(true))
      .pipe(takeUntilDestroyed(this.destroyRef), finalize(() => this.loading.set(false)))
      .subscribe({
        next: report => this.report.set(report),
        error: (error: unknown) => {
          if (!this.sending()) {
            this.error.set('Не удалось загрузить тестовый набор. Повторите попытку.');
          } else if (error instanceof HttpErrorResponse && error.status > 0) {
            this.error.set(error.error?.error?.message || 'Система не смогла обработать импорт. Повторите попытку.');
          } else {
            this.error.set('Ответ на импорт не получен. Часть заказов могла сохраниться. Повторная загрузка безопасна: новые копии заказов не создаются.');
          }
        },
      });
  }
}
