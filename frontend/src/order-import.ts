import { Injectable, inject } from '@angular/core';
import { HttpClient } from '@angular/common/http';
import { switchMap, timeout } from 'rxjs';

export type Outcome = 'created' | 'updated' | 'unchanged' | 'duplicate' | 'rejected';
export type OrderStatus = 'new' | 'accepted' | 'delivering' | 'delivered' | 'cancelled';
export interface Reason { code: string; message: string; }
export interface ImportRecord {
  index: number;
  marketplace_id: string | null;
  outcome: Outcome;
  reason: Reason;
  warnings: Reason[];
  order?: {
    id: number;
    status: OrderStatus;
    needs_review: boolean;
    items_total_kopecks: number;
    marketplace_total_kopecks: number;
    delivery_total_kopecks: number;
    grand_total_kopecks: number;
  };
}
export interface ImportResponse {
  shop_id: number;
  total: number;
  summary: Record<Outcome, number>;
  results: ImportRecord[];
}

@Injectable({ providedIn: 'root' })
export class OrderImportService {
  private readonly http = inject(HttpClient);

  importOrders(onSending: () => void) {
    return this.http.get<{ orders: unknown[] }>('/assets/marketplace-orders.json').pipe(
      timeout(10000),
      switchMap(payload => {
        onSending();
        return this.http.post<ImportResponse>('/api/shops/1/orders/import', payload)
          .pipe(timeout(30000));
      }),
    );
  }
}
